# shopwise-checkout

Orchestrates the checkout saga of ADR-0004 (`shopwise-platform/docs/adr/0004-checkout-saga.md`): turns a customer's
cart into an order across inventory, payments and orders, and undoes what was done when a step fails. Created from
`shopwise-service-template`: structure, checks and how to run PHP are described there and still apply.

## Service facts
- **Owns**: `checkouts` (the saga row: state, snapshot lines, totals, ids from the participants, `next_attempt_at`,
  `compensations`, stored `trace_context`, `version`) and `checkout_steps` (append-only step log). Database `checkout`.
- **Publishes** (outbox, key = checkoutId):
  - commands (topics owned by the receivers): `inventory.reserve-stock`, `inventory.commit-reservation`,
    `inventory.release-reservation`, `payments.request-payment`, `payments.refund-payment`, `orders.place-order`,
    `orders.cancel-order`;
  - events (topics owned here): `checkout.completed`, `checkout.failed`.
- **Consumes** (consumer `replies`, group `checkout`): `inventory.stock-reserved`, `inventory.reservation-failed`,
  `inventory.reservation-committed`, `inventory.reservation-released`, `payments.payment-requires-action`,
  `payments.payment-succeeded`, `payments.payment-failed`, `payments.refunded`, `orders.order-placed`,
  `orders.order-rejected`, `orders.order-cancelled`.
- **HTTP** (BFF only), contract `shopwise-contracts/openapi/checkout.yaml`: `POST /api/checkouts` (`auth.jwt`,
  `Idempotency-Key` header), `GET /api/checkouts/{id}` (owner only, else 404; the only place `clientSecret` and
  `paymentActionExpiresAt` are shown), `GET /api/admin/checkouts` (`auth.jwt:admin`).
- **REST dependencies**: cart `POST /api/carts/{id}/snapshot {checkoutId}` (caller's token; takes the cart lock),
  `POST /internal/carts/{id}/checked-out` and `POST /internal/carts/{id}/unlock` (no token); pricing `POST /api/quotes`
  (caller's token). Identity's JWKS for tokens.
- **Scheduled**: `checkout:time-out-steps` every 10 s, plus the kit's outbox relay.
- **Local URL**: http://localhost:8106 (Tilt port-forward).

## How the saga works
| Path | Role |
|---|---|
| `app/Actions/StartCheckout` | steps 1-2 in the request (snapshot + lock for a pre-made checkout id, quote), then the row + first command in one transaction; unlocks whenever no saga starts |
| `app/Saga/CheckoutSaga` | every transition; must run in a transaction; checks the expected state, saves with `saveVersioned()`, records the next command |
| `app/Saga/SagaMessages` | builds commands/events from the row; the only place that calls `Outbox::record` |
| `app/Saga/SagaTrace` | one checkout = one trace: request trace on start, stored trace for scheduler work |
| `app/Messaging/Handlers` | one per reply type: parse it with its contract class, find the checkout (`App\Messaging\Reply`), call the saga |
| `app/Actions/CloseCheckoutCart` | step 7 over REST, after commit; never compensated, retried with backoff |
| `app/Actions/UnlockCheckoutCart` | gives a failed checkout's cart back over REST, after commit; retried like step 7 |
| `app/Actions/TimeOutCheckoutSteps` | the scheduler pass: resend, time out, resend undo commands, retry cart closing and unlocking |
| `app/Clients` | `ServiceClient` (timeouts, retry on connection errors/5xx, cache-backed circuit breaker, trace headers) |

Rules:
- **Optimistic locking**: `Checkout::saveVersioned()` updates only `WHERE version = <read version>` and throws
  `StaleCheckout`; inside a reply that rolls back the transaction (including the processed-message row), so the kit
  retries the message. The scheduler skips the saga until its next pass. Never save a checkout with plain `save()`.
- **Replies in the wrong state are logged as `ignored`, never applied**: that is how re-published outcomes (new envelope
  id, same data, after a command was re-sent) are made harmless. Transport duplicates are dropped by the kit.
- **Timeouts**: a forward command is re-sent when its reply is 30 s late, at most 3 times; then the step times out.
  Undo commands follow the same 30 s × 3 and then every 5 minutes until confirmed. A checkout stays `compensating`
  until every undo is confirmed, then publishes `checkout.failed`.
- **3-D Secure**: `payments.payment-requires-action` moves `paying` → `awaiting_payment_action` and stores the client
  secret and `expiresAt`; the step's deadline is `expiresAt` + 1 min, then the normal re-sends and timeout. Succeeded
  and failed are accepted in both states; the secret is cleared whenever the checkout leaves the state.
- **Compensation plans** (waves run in order, steps in a wave in parallel): reserve failed → nothing; reserve timeout →
  release; payment failed → release; payment timeout (also in `awaiting_payment_action`) → refund, then release;
  reservation expired at commit → refund; commit timeout → refund + release; order rejected → refund + release;
  order timeout → cancel-order + refund + release. `payments.payment-failed` or `payments.refunded` both confirm a
  refund; any `inventory.reservation-released` confirms a release; `orders.order-cancelled` confirms the cancel.
  `refund-payment` always carries `customerId`, `amount` (the total) and `currency`.
- **Late successes**: a `stock-reserved`, `payment-requires-action` or `payment-succeeded` arriving after the saga
  gave up sends the undo again (the status does not change). An `order-placed`, or an `order-rejected` with reason
  `cancelled`, while cancel-order is in the plan is ignored: orders cancels that order. `payments.refunded` with
  reason `external` (a refund made in Stripe) is always ignored.
- **Cart lock**: the snapshot locks the cart for the checkout id. Every failure unlocks it: in the request (any error
  after the snapshot, best effort, logged when it fails) and when the saga fails (`UnlockCheckoutCart` after commit,
  then the close-cart backoff from `next_attempt_at`, error logged after an hour; 404/422 is permanent).
- **Closing the cart** (step 7) runs after the order-placed transaction commits, then on the scheduler with
  exponential backoff (5 s doubling to 5 min); after an hour it logs one error. A 404/409/422 from cart is permanent:
  the checkout completes anyway with an error logged.
- `POST /api/checkouts`: same `Idempotency-Key` (per customer) → 200 with the existing checkout; another active
  checkout for the cart, or cart `409 cart_locked` → 409 (`active_cart_id` is unique while a saga runs);
  cart/pricing unavailable → 503.
- Every command, event and reply goes through its generated contract class (`Shopwise\Contracts\...V1`): built with
  `new` + `toArray()` in `SagaMessages`, parsed with `fromArray()` in the handlers.

## Configuration
`config/checkout.php` (saga timing) and `config/services.php` (`cart`, `pricing`, `http`). Env: `CART_BASE_URL`,
`PRICING_BASE_URL`, `CART_TIMEOUT_SECONDS`/`PRICING_TIMEOUT_SECONDS` (3), `*_CONNECT_TIMEOUT_SECONDS` (1),
`*_RETRIES` (2), `SERVICE_HTTP_RETRY_SLEEP_MS` (100), `SERVICE_HTTP_BREAKER_THRESHOLD` (5),
`SERVICE_HTTP_BREAKER_COOLDOWN_SECONDS` (30), `CHECKOUT_REPLY_TIMEOUT_SECONDS` (30), `CHECKOUT_MAX_RESENDS` (3),
`CHECKOUT_COMPENSATION_RETRY_SECONDS` (300), `CHECKOUT_PAYMENT_ACTION_GRACE_SECONDS` (60),
`CHECKOUT_CLOSE_CART_BACKOFF_SECONDS` (5),
`CHECKOUT_CLOSE_CART_MAX_BACKOFF_SECONDS` (300), `CHECKOUT_CLOSE_CART_ALERT_AFTER_SECONDS` (3600).

## Tests
Cart and pricing are faked with `Http::fake` against `cart.test`/`pricing.test` (`phpunit.xml`; stray requests fail);
`cartCloseAnswers()`/`cartUnlockAnswers()` change the internal endpoints' answers mid-test. Replies come from
the contract examples with a fresh envelope id (`reply()` in `tests/Pest.php`), and `passDeadline()` moves the clock
and runs the scheduler pass. Every new transition gets a test for its reply in the expected state, in a wrong state,
and redelivered.
