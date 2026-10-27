<?php

declare(strict_types=1);

use App\Enums\CheckoutStatus;
use App\Models\Checkout;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Shopwise\Kit\Messaging\OutboxMessage;

function postCheckout(array $body = [], string $key = 'attempt-0001', array $headers = []): TestResponse
{
    return test()->postJson('/api/checkouts', checkoutBody($body), bearer() + ['Idempotency-Key' => $key] + $headers);
}

it('takes the snapshot and quote with the caller token, starts the saga and sends inventory.reserve-stock', function (): void {
    fakeServices();

    $response = postCheckout();

    $checkout = Checkout::query()->sole();
    $response->assertAccepted()
        ->assertHeader('Location', "/api/checkouts/{$checkout->id}")
        ->assertJsonPath('data.id', $checkout->id)
        ->assertJsonPath('data.status', 'reserving')
        ->assertJsonPath('data.cartId', CART_ID)
        ->assertJsonPath('data.customerId', CUSTOMER_ID)
        ->assertJsonPath('data.orderId', null)
        ->assertJsonPath('data.failureReason', null)
        ->assertJsonPath('data.paymentFailure', null)
        ->assertJsonPath('data.total', 7300)
        ->assertJsonPath('data.lines.0', ['sku' => 'TEE-MER-BLA-M', 'productName' => 'Merino Tee', 'variantName' => 'Black / M', 'quantity' => 2, 'unitAmount' => 2900, 'lineAmount' => 5800])
        ->assertJsonPath('data.lines.1.productName', null);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://cart.test/api/carts/'.CART_ID.'/snapshot'
        && str_starts_with(bearerHeaderOf($request), 'Bearer '));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://pricing.test/api/quotes'
        && $request['lines'] === [['sku' => 'TEE-MER-BLA-M', 'quantity' => 2], ['sku' => 'MUG-CER-WHI', 'quantity' => 1]]
        && ! isset($request['couponCode'])
        && str_starts_with($request->header('Authorization')[0], 'Bearer '));

    expect($checkout->cart_version)->toBe(7)
        ->and($checkout->customer_email)->toBe('customer@shopwise.test')
        ->and($checkout->active_cart_id)->toBe(CART_ID)
        ->and(outbox('inventory.reserve-stock'))->toHaveCount(1)
        ->and(outbox('inventory.reserve-stock')[0])->toMatchArray([
            'checkoutId' => $checkout->id,
            'lines' => [['sku' => 'TEE-MER-BLA-M', 'quantity' => 2], ['sku' => 'MUG-CER-WHI', 'quantity' => 1]],
        ])
        ->and(OutboxMessage::query()->sole()->subject)->toBe($checkout->id)
        ->and($checkout->steps()->pluck('step')->map->value->all())->toBe(['start', 'reserve_stock']);
});

function bearerHeaderOf(Request $request): string
{
    return $request->header('Authorization')[0] ?? '';
}

it('keeps the coupon pricing applied', function (): void {
    fakeServices(cartSnapshot(couponCode: 'WELCOME10'), pricingQuote(['code' => 'WELCOME10', 'valid' => true, 'reason' => null, 'description' => '10% off']));

    postCheckout()->assertAccepted()->assertJsonPath('data.couponCode', 'WELCOME10')->assertJsonPath('data.discount', 730);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://pricing.test/api/quotes' && $request['couponCode'] === 'WELCOME10');
});

it('returns the same checkout for the same Idempotency-Key without calling cart again', function (): void {
    fakeServices();
    $first = postCheckout()->assertAccepted();

    postCheckout()->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

    Http::assertSentCount(2);
    expect(Checkout::query()->count())->toBe(1)->and(outbox('inventory.reserve-stock'))->toHaveCount(1);
});

it('answers 409 when the cart already has a checkout in progress under another key', function (): void {
    fakeServices();
    postCheckout(key: 'attempt-0001')->assertAccepted();

    postCheckout(key: 'attempt-0002')->assertConflict();

    expect(Checkout::query()->count())->toBe(1);
});

it('allows a new checkout of the same cart once the previous one failed', function (): void {
    fakeServices();
    postCheckout(key: 'attempt-0001')->assertAccepted();
    reply(Checkout::query()->sole(), 'inventory.reservation-failed.v1');

    postCheckout(key: 'attempt-0002')->assertAccepted();

    expect(Checkout::query()->count())->toBe(2);
});

it('answers 404 for a cart cart does not show the caller', function (): void {
    Http::fake(['cart.test/*' => Http::response(['message' => 'Not found'], 404)]);

    postCheckout()->assertNotFound();

    expect(Checkout::query()->count())->toBe(0);
});

it('answers 404 when the snapshot belongs to someone else', function (): void {
    fakeServices(cartSnapshot(customerId: 'someone-else'));

    postCheckout()->assertNotFound();
});

it('rejects a cart that cannot be checked out with 422 and starts nothing', function (array $snapshot, array $quote, string $field): void {
    fakeServices($snapshot, $quote);

    postCheckout()->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(Checkout::query()->count())->toBe(0)->and(OutboxMessage::query()->count())->toBe(0);
})->with([
    'empty cart' => fn (): array => [cartSnapshot([]), pricingQuote(), 'cartId'],
    'line no longer for sale' => fn (): array => [cartSnapshot([['sku' => 'OLD-1', 'quantity' => 1, 'productName' => null, 'variantName' => 'Old', 'available' => false]]), pricingQuote(), 'cartId'],
    'line without a price' => fn (): array => [cartSnapshot(), pricingQuote(mugAmount: null), 'lines'],
    'coupon already redeemed' => fn (): array => [cartSnapshot(couponCode: 'WELCOME10'), pricingQuote(['code' => 'WELCOME10', 'valid' => false, 'reason' => 'already_redeemed', 'description' => null]), 'couponCode'],
]);

it('passes the pricing reason as the coupon error', function (): void {
    fakeServices(cartSnapshot(couponCode: 'SAVE5'), pricingQuote(['code' => 'SAVE5', 'valid' => false, 'reason' => 'exhausted', 'description' => null]));

    postCheckout()->assertUnprocessable()->assertJsonPath('errors.couponCode.0', 'exhausted');
});

it('validates the request', function (array $body, array $headers, string $field): void {
    $response = test()->postJson('/api/checkouts', checkoutBody($body), bearer() + $headers);

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no Idempotency-Key' => [[], [], 'idempotencyKey'],
    'short Idempotency-Key' => [[], ['Idempotency-Key' => 'abc'], 'idempotencyKey'],
    'not a Stripe payment method' => [['paymentMethodId' => 'card_123'], ['Idempotency-Key' => 'attempt-0001'], 'paymentMethodId'],
    'lowercase country' => [['shippingAddress' => ['name' => 'A', 'line1' => 'B', 'city' => 'C', 'postalCode' => 'D', 'country' => 'gb']], ['Idempotency-Key' => 'attempt-0001'], 'shippingAddress.country'],
    'no cart' => [['cartId' => null], ['Idempotency-Key' => 'attempt-0001'], 'cartId'],
]);

it('needs a customer token', function (): void {
    $this->postJson('/api/checkouts', checkoutBody(), ['Idempotency-Key' => 'attempt-0001'])->assertUnauthorized();
});

it('retries cart on a 5xx and goes on when it recovers', function (): void {
    Http::fake([
        'cart.test/*' => Http::sequence()->push([], 503)->push(cartSnapshot()),
        'pricing.test/*' => Http::response(pricingQuote()),
    ]);

    postCheckout()->assertAccepted();

    Http::assertSentCount(3);
});

it('answers 503 when cart keeps failing, and opens the circuit after five failed requests', function (): void {
    config(['services.cart.retries' => 0]);
    Http::fake(['cart.test/*' => Http::response([], 500)]);

    foreach (range(1, 5) as $attempt) {
        postCheckout(key: "attempt-000{$attempt}")->assertServiceUnavailable();
    }

    Http::assertSentCount(5);

    postCheckout(key: 'attempt-0006')->assertServiceUnavailable();
    Http::assertSentCount(5);

    $this->travel(31)->seconds();
    postCheckout(key: 'attempt-0007')->assertServiceUnavailable();
    Http::assertSentCount(6);
    expect(Checkout::query()->count())->toBe(0);
});

it('answers 503 when pricing times out', function (): void {
    Http::fake([
        'cart.test/*' => Http::response(cartSnapshot()),
        'pricing.test/*' => Http::failedConnection(),
    ]);

    postCheckout()->assertServiceUnavailable();

    expect(Checkout::query()->count())->toBe(0);
});

it('sends the request trace context to cart, pricing and the outbox', function (): void {
    fakeServices();

    postCheckout(headers: ['traceparent' => TRACEPARENT])->assertAccepted();

    Http::assertSent(fn (Request $request): bool => str_contains($request->header('traceparent')[0] ?? '', TRACE_ID));
    expect(Http::recorded())->toHaveCount(2)
        ->and(Http::recorded()->every(fn (array $pair): bool => str_contains($pair[0]->header('traceparent')[0] ?? '', TRACE_ID)))->toBeTrue()
        ->and(OutboxMessage::query()->sole()->headers['traceparent'])->toContain(TRACE_ID)
        ->and(Checkout::query()->sole()->trace_context['traceparent'] ?? null)->toContain(TRACE_ID)
        ->and(Checkout::query()->sole()->status)->toBe(CheckoutStatus::Reserving);
});
