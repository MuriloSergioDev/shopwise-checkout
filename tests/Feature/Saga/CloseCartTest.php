<?php

declare(strict_types=1);

use App\Enums\CheckoutStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('retries closing the cart with backoff while cart is down, then completes', function (): void {
    $checkout = checkoutIn(CheckoutStatus::PlacingOrder);
    cartCloseAnswers(fn () => Http::response([], 503));

    reply($checkout, 'orders.order-placed.v1');

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::ClosingCart)
        ->and($checkout->step_attempts)->toBe(1)
        ->and($checkout->next_attempt_at?->diffInSeconds(now()))->toBeLessThanOrEqual(5.0);

    passDeadline(6);
    expect($checkout->refresh()->step_attempts)->toBe(2)
        ->and(now()->diffInSeconds($checkout->next_attempt_at))->toBeGreaterThan(9.0)->toBeLessThanOrEqual(10.0);

    cartCloseAnswers(fn () => Http::response());
    passDeadline(11);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Completed)
        ->and(outbox('checkout.completed'))->toHaveCount(1);
});

it('never compensates a cart that will not close, and logs an error once after an hour', function (): void {
    $checkout = checkoutIn(CheckoutStatus::PlacingOrder);
    cartCloseAnswers(fn () => Http::failedConnection());
    reply($checkout, 'orders.order-placed.v1');
    Log::spy();

    foreach (range(1, 14) as $pass) {
        passDeadline(301);
    }

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::ClosingCart)
        ->and($checkout->close_cart_alerted_at)->not->toBeNull()
        ->and(outbox('payments.refund-payment'))->toBe([])
        ->and(outbox('inventory.release-reservation'))->toBe([]);
    Log::shouldHaveReceived('error')->with('Cart still not closed for a placed order', Mockery::any())->once();
});

it('completes when cart refuses for good (already closed by another checkout)', function (): void {
    $checkout = checkoutIn(CheckoutStatus::PlacingOrder);
    cartCloseAnswers(fn () => Http::response(['message' => 'Closed by another checkout'], 409));

    reply($checkout, 'orders.order-placed.v1');

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Completed)
        ->and(outbox('checkout.completed'))->toHaveCount(1);
});
