<?php

declare(strict_types=1);

use App\Enums\CheckoutStatus;
use App\Enums\SagaStep;
use App\Enums\StepOutcome;
use App\Models\Checkout;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Deadlines are compared to the second; a running clock would add real test time to the travelled time.
beforeEach(fn () => $this->freezeTime());

function failedCheckout(): Checkout
{
    $checkout = startCheckout();
    reply($checkout, 'inventory.reservation-failed.v1');

    return $checkout->refresh();
}

it('unlocks the cart right after the checkout fails', function (): void {
    $checkout = failedCheckout();

    expect($checkout->status)->toBe(CheckoutStatus::Failed)
        ->and($checkout->cart_unlocked_at)->not->toBeNull()
        ->and($checkout->next_attempt_at)->toBeNull()
        ->and($checkout->steps()->where('step', SagaStep::UnlockCart)->sole()->outcome)->toBe(StepOutcome::Replied);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://cart.test/internal/carts/'.CART_ID.'/unlock'
        && $request['checkoutId'] === $checkout->id
        && ! $request->hasHeader('Authorization'));
});

it('retries unlocking with backoff while cart is down, and logs an error after an hour', function (): void {
    config(['services.cart.retries' => 0]);
    cartUnlockAnswers(fn () => Http::response([], 503));
    $checkout = failedCheckout();

    expect($checkout->cart_unlocked_at)->toBeNull()
        ->and($checkout->step_attempts)->toBe(1)
        ->and($checkout->next_attempt_at?->toIso8601ZuluString())->toBe(now()->addSeconds(5)->toIso8601ZuluString());

    passDeadline(5);
    expect($checkout->refresh()->step_attempts)->toBe(2)
        ->and($checkout->next_attempt_at?->toIso8601ZuluString())->toBe(now()->addSeconds(10)->toIso8601ZuluString());

    Log::spy();
    $this->travel(1)->hours();
    passDeadline(0);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => $message === 'Cart still locked by a failed checkout')->once();
    expect($checkout->refresh()->cart_unlock_alerted_at)->not->toBeNull();

    cartUnlockAnswers(fn () => Http::response(['data' => ['cartId' => CART_ID, 'lockedByCheckoutId' => null]]));
    $this->travel(5)->minutes();
    passDeadline(0);

    expect($checkout->refresh()->cart_unlocked_at)->not->toBeNull()
        ->and($checkout->next_attempt_at)->toBeNull()
        ->and($checkout->status)->toBe(CheckoutStatus::Failed)
        ->and(outbox('checkout.failed'))->toHaveCount(1);
});

it('gives up on a cart that refuses to unlock for good', function (): void {
    cartUnlockAnswers(fn () => Http::response(['message' => 'Not found'], 404));

    $checkout = failedCheckout();

    expect($checkout->cart_unlocked_at)->not->toBeNull()
        ->and($checkout->next_attempt_at)->toBeNull()
        ->and($checkout->steps()->where('step', SagaStep::UnlockCart)->sole()->outcome)->toBe(StepOutcome::Failed);
});

it('does not unlock a cart of a checkout that completed', function (): void {
    $checkout = checkoutIn(CheckoutStatus::PlacingOrder);

    reply($checkout, 'orders.order-placed.v1');

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Completed)
        ->and(unlockCalls())->toBe([]);
});
