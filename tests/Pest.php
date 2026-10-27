<?php

declare(strict_types=1);

use App\Enums\CheckoutStatus;
use App\Models\Checkout;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Shopwise\Kit\Auth\KeySource;
use Shopwise\Kit\Messaging\DispatchResult;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageDispatcher;
use Shopwise\Kit\Messaging\OutboxMessage;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

const CUSTOMER_ID = '01JBCDEFGHJKMNPQRSTVWXYZ11';

const CART_ID = '01JBCDEFGHJKMNPQRSTVWXYZ30';

const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

const TRACEPARENT = '00-'.TRACE_ID.'-00f067aa0ba902b7-01';

/**
 * Loads the contract example envelope (shopwise-contracts/examples), optionally overriding data and envelope fields.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $envelope
 */
function contractExample(string $typeAndVersion, array $data = [], array $envelope = []): Envelope
{
    $json = file_get_contents(base_path("vendor/shopwise/contracts/examples/{$typeAndVersion}.json"));
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $original */
    $original = $payload['data'];
    $payload['data'] = array_replace($original, $data);

    return Envelope::fromArray(array_replace($payload, $envelope));
}

/**
 * A reply for the checkout, built from the contract example with a fresh envelope id (as a re-published outcome
 * would have), dispatched through the kit like the consumer does.
 *
 * @param  array<string, mixed>  $data
 */
function reply(Checkout $checkout, string $typeAndVersion, array $data = []): Envelope
{
    $envelope = contractExample($typeAndVersion, ['checkoutId' => $checkout->id, ...$data], ['id' => (string) Str::ulid()]);
    deliver($envelope);

    return $envelope;
}

function deliver(Envelope $envelope): DispatchResult
{
    return app(MessageDispatcher::class)->dispatch($envelope, $envelope->type, backoffMs: 0);
}

/**
 * Data of the outbox rows of one type (commands and events), oldest first.
 *
 * @return list<array<string, mixed>>
 */
function outbox(string $type): array
{
    /** @var list<array<string, mixed>> */
    return OutboxMessage::query()->where('type', $type)->orderBy('id')->get()
        ->map(fn (OutboxMessage $message): array => $message->envelope['data'])
        ->all();
}

/**
 * Authorization header with an identity-style access token. The test key replaces identity's JWKS.
 *
 * @param  list<string>  $roles
 * @return array{Authorization: string}
 */
function bearer(string $customerId = CUSTOMER_ID, array $roles = ['customer']): array
{
    static $privateKey = null;
    static $publicKey = null;
    if ($privateKey === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $publicKey = openssl_pkey_get_details($key)['key'];
    }

    app()->instance(KeySource::class, new readonly class($publicKey) implements KeySource
    {
        public function __construct(private string $publicKey) {}

        public function keys(bool $refresh = false): array
        {
            return ['test' => new Key($this->publicKey, 'RS256')];
        }
    });

    $token = JWT::encode([
        'iss' => 'shopwise-identity',
        'sub' => $customerId,
        'email' => 'customer@shopwise.test',
        'roles' => $roles,
        'iat' => time(),
        'exp' => time() + 900,
    ], $privateKey, 'RS256', 'test');

    return ['Authorization' => "Bearer {$token}"];
}

/**
 * Cart's snapshot response body (contract CartSnapshot).
 *
 * @param  list<array<string, mixed>>|null  $lines
 * @return array{data: array<string, mixed>}
 */
function cartSnapshot(?array $lines = null, ?string $couponCode = null, string $customerId = CUSTOMER_ID): array
{
    return ['data' => [
        'cartId' => CART_ID,
        'customerId' => $customerId,
        'version' => 7,
        'couponCode' => $couponCode,
        'lines' => $lines ?? [
            ['sku' => 'TEE-MER-BLA-M', 'quantity' => 2, 'productName' => 'Merino Tee', 'variantName' => 'Black / M', 'available' => true],
            ['sku' => 'MUG-CER-WHI', 'quantity' => 1, 'productName' => null, 'variantName' => 'White', 'available' => true],
        ],
        'takenAt' => '2026-10-27T10:00:00Z',
    ]];
}

/**
 * Pricing's quote response body (contract Quote) for the default snapshot.
 *
 * @param  array<string, mixed>|null  $coupon
 * @return array{data: array<string, mixed>}
 */
function pricingQuote(?array $coupon = null, ?int $mugAmount = 1500): array
{
    $discount = $coupon !== null && ($coupon['valid'] ?? false) ? 730 : 0;
    $subtotal = 5800 + ($mugAmount ?? 0);

    return ['data' => [
        'currency' => 'USD',
        'lines' => [
            ['sku' => 'TEE-MER-BLA-M', 'quantity' => 2, 'unitAmount' => 2900, 'lineAmount' => 5800],
            ['sku' => 'MUG-CER-WHI', 'quantity' => 1, 'unitAmount' => $mugAmount, 'lineAmount' => $mugAmount],
        ],
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => $subtotal - $discount,
        'coupon' => $coupon,
    ]];
}

/**
 * Fakes cart and pricing with healthy answers unless overridden.
 *
 * @param  array<string, mixed>|null  $snapshot
 * @param  array<string, mixed>|null  $quote
 */
function fakeServices(?array $snapshot = null, ?array $quote = null): void
{
    Http::fake([
        'cart.test/api/carts/*/snapshot' => Http::response($snapshot ?? cartSnapshot()),
        'cart.test/internal/carts/*/checked-out' => fn () => app()->bound('test.cart-close') ? app('test.cart-close')() : Http::response(),
        'cart.test/internal/carts/*/unlock' => fn () => app()->bound('test.cart-unlock') ? app('test.cart-unlock')() : Http::response(['data' => ['cartId' => CART_ID, 'lockedByCheckoutId' => null]]),
        'pricing.test/api/quotes' => Http::response($quote ?? pricingQuote()),
    ]);
}

/**
 * Changes how the faked cart answers POST /internal/carts/{id}/checked-out from now on.
 *
 * @param  Closure(): mixed  $answer  e.g. fn () => Http::response([], 503), or fn () => Http::failedConnection()
 */
function cartCloseAnswers(Closure $answer): void
{
    app()->instance('test.cart-close', $answer);
}

/**
 * Changes how the faked cart answers POST /internal/carts/{id}/unlock from now on.
 *
 * @param  Closure(): mixed  $answer
 */
function cartUnlockAnswers(Closure $answer): void
{
    app()->instance('test.cart-unlock', $answer);
}

/**
 * The unlock calls cart received, as their checkoutIds.
 *
 * @return list<string>
 */
function unlockCalls(): array
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/unlock'))
        ->map(fn (array $pair): string => (string) $pair[0]['checkoutId'])
        ->values()
        ->all();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checkoutBody(array $overrides = []): array
{
    return array_replace([
        'cartId' => CART_ID,
        'paymentMethodId' => 'pm_card_visa',
        'shippingAddress' => ['name' => 'Ada Lovelace', 'line1' => '1 Main St', 'city' => 'London', 'postalCode' => 'N1 1AA', 'country' => 'GB'],
    ], $overrides);
}

/**
 * Starts a checkout through the API against healthy fakes and returns it (status reserving).
 */
function startCheckout(): Checkout
{
    fakeServices();
    test()->postJson('/api/checkouts', checkoutBody(), bearer() + ['Idempotency-Key' => (string) Str::uuid()])->assertAccepted();

    return Checkout::query()->latest('id')->firstOrFail();
}

/**
 * Drives a fresh checkout forward with successful replies until it waits in $status.
 */
function checkoutIn(CheckoutStatus $status): Checkout
{
    $checkout = startCheckout();
    $successes = [
        'inventory.stock-reserved.v1',
        'payments.payment-succeeded.v1',
        'inventory.reservation-committed.v1',
        'orders.order-placed.v1',
    ];

    foreach ($successes as $type) {
        if ($checkout->refresh()->status === $status) {
            break;
        }

        reply($checkout, $type);
    }

    expect($checkout->refresh()->status)->toBe($status);

    return $checkout;
}

/**
 * Runs the scheduler's saga pass after moving the clock.
 */
function passDeadline(int $seconds = 31): void
{
    test()->travel($seconds)->seconds();
    test()->artisan('checkout:time-out-steps')->assertSuccessful();
}
