# shopwise-checkout

Orchestrates the checkout saga of ADR-0004 (`shopwise-platform/docs/adr/0004-checkout-saga.md`): turns a customer's
cart into an order across inventory, payments and orders, and undoes what was done when a step fails. Created from
`shopwise-service-template`: structure, checks and how to run PHP are described there and still apply.

## Service facts
- **Owns**: `checkouts` (the saga row: state, snapshot lines, totals, ids from the participants, `next_attempt_at`,
  `compensations`, stored `trace_context`, `version`) and `checkout_steps` (append-only step log). Database `checkout`.
- **Publishes** (outbox, key = checkoutId):
  - commands (topics owned by the receivers): `inventory.reserve-stock`, `inventory.commit-reservation`,
    `inventory.release-reservation`, `payments.request-payment`, `payments.refund-payment`, `orders.place-order`;
  - events (topics owned here): `checkout.completed`, `checkout.failed`.
- **Consumes** (consumer `replies`, group `checkout`): `inventory.stock-reserved`, `inventory.reservation-failed`,
  `inventory.reservation-committed`, `inventory.reservation-released`, `payments.payment-succeeded`,
  `payments.payment-failed`, `payments.refunded`, `orders.order-placed`, `orders.order-rejected`.
- **HTTP** (BFF only), contract `shopwise-contracts/openapi/checkout.yaml`: `POST /api/checkouts` (`auth.jwt`,
  `Idempotency-Key` header), `GET /api/checkouts/{id}` (owner only, else 404), `GET /api/admin/checkouts`
  (`auth.jwt:admin`).
- **REST dependencies**: cart `GET /api/carts/{id}/snapshot` (caller's token) and `POST /internal/carts/{id}/checked-out`
  (no token); pricing `POST /api/quotes` (caller's token). Identity's JWKS for tokens.
- **Scheduled**: `checkout:time-out-steps` every 10 s, plus the kit's outbox relay.
- **Local URL**: http://localhost:8106 (Tilt port-forward).

## How the saga works
| Path | Role |
|---|---|
| `app/Actions/StartCheckout` | steps 1-2 in the request (snapshot, quote), then the row + first command in one transaction |
| `app/Saga/CheckoutSaga` | every transition; must run in a transaction; checks the expected state, saves with `saveVersioned()`, records the next command |
| `app/Saga/SagaMessages` | builds commands/events from the row; the only place that calls `Outbox::record` |
| `app/Saga/SagaTrace` | one checkout = one trace: request trace on start, stored trace for scheduler work |
| `app/Messaging/Handlers` | one per reply type: read the payload (`App\Messaging\Reply`), call the saga |
| `app/Actions/CloseCheckoutCart` | step 7 over REST, after commit; never compensated, retried with backoff |
| `app/Actions/TimeOutCheckoutSteps` | the scheduler pass: resend, time out, resend undo commands, retry cart closing |
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
- **Compensation plans** (waves run in order, steps in a wave in parallel): reserve failed → nothing; reserve timeout →
  release; payment failed → release; payment timeout → refund, then release; reservation expired at commit → refund;
  commit timeout (not in ADR-0004) → refund + release; order rejected or timeout → refund + release.
  `payments.payment-failed` or `payments.refunded` both confirm a refund; any `inventory.reservation-released`
  confirms a release.
- **Late successes**: a `stock-reserved` or `payment-succeeded` arriving after the saga gave up sends the undo again
  (the status does not change). An `order-placed` after the order step timed out is logged as an error: orders has
  no cancel command yet.
- **Closing the cart** (step 7) runs after the order-placed transaction commits, then on the scheduler with
  exponential backoff (5 s doubling to 5 min); after an hour it logs one error. A 404/409/422 from cart is permanent:
  the checkout completes anyway with an error logged.
- `POST /api/checkouts`: same `Idempotency-Key` (per customer) → 200 with the existing checkout; another active
  checkout for the cart → 409 (`active_cart_id` is unique while a saga runs); cart/pricing unavailable → 503.
- Payloads with nullable fields are built and read as arrays: the generated contract DTOs type them as non-null.

## Configuration
`config/checkout.php` (saga timing) and `config/services.php` (`cart`, `pricing`, `http`). Env: `CART_BASE_URL`,
`PRICING_BASE_URL`, `CART_TIMEOUT_SECONDS`/`PRICING_TIMEOUT_SECONDS` (3), `*_CONNECT_TIMEOUT_SECONDS` (1),
`*_RETRIES` (2), `SERVICE_HTTP_RETRY_SLEEP_MS` (100), `SERVICE_HTTP_BREAKER_THRESHOLD` (5),
`SERVICE_HTTP_BREAKER_COOLDOWN_SECONDS` (30), `CHECKOUT_REPLY_TIMEOUT_SECONDS` (30), `CHECKOUT_MAX_RESENDS` (3),
`CHECKOUT_COMPENSATION_RETRY_SECONDS` (300), `CHECKOUT_CLOSE_CART_BACKOFF_SECONDS` (5),
`CHECKOUT_CLOSE_CART_MAX_BACKOFF_SECONDS` (300), `CHECKOUT_CLOSE_CART_ALERT_AFTER_SECONDS` (3600).

## Tests
Cart and pricing are faked with `Http::fake` against `cart.test`/`pricing.test` (`phpunit.xml`); replies come from
the contract examples with a fresh envelope id (`reply()` in `tests/Pest.php`), and `passDeadline()` moves the clock
and runs the scheduler pass. Every new transition gets a test for its reply in the expected state, in a wrong state,
and redelivered.
