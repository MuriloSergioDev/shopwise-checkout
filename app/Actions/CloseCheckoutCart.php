<?php

declare(strict_types=1);

namespace App\Actions;

use App\Clients\CartClient;
use App\Enums\CheckoutStatus;
use App\Exceptions\DependencyUnavailable;
use App\Exceptions\StaleCheckout;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use App\Saga\SagaMessages;
use App\Saga\SagaTrace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step 7: closes the cart over REST once the order exists, then completes the checkout. Never compensated;
 * a failed attempt is rescheduled with backoff. Runs outside any transaction (it makes an HTTP call) and never
 * throws, so it is safe as an after-commit callback.
 */
final readonly class CloseCheckoutCart
{
    public function __construct(private CartClient $cart, private CheckoutSaga $saga) {}

    public function handle(string $checkoutId): void
    {
        $checkout = Checkout::query()->find($checkoutId);
        if (! $checkout instanceof Checkout || $checkout->status !== CheckoutStatus::ClosingCart) {
            return;
        }

        SagaTrace::forCheckout($checkout, 'checkout close cart', function () use ($checkout): void {
            try {
                $closed = $this->cart->markCheckedOut(
                    $checkout->cart_id,
                    $checkout->id,
                    (string) $checkout->order_id,
                    SagaMessages::stockLines($checkout),
                );
                $this->transition($checkout->id, fn (Checkout $fresh) => $this->saga->cartClosed($fresh, $closed));
            } catch (DependencyUnavailable $e) {
                $this->transition($checkout->id, fn (Checkout $fresh) => $this->saga->cartCloseFailed($fresh, $e->getMessage()));
            }
        });
    }

    /**
     * @param  callable(Checkout): void  $transition
     */
    private function transition(string $checkoutId, callable $transition): void
    {
        try {
            DB::transaction(function () use ($checkoutId, $transition): void {
                $transition(Checkout::query()->findOrFail($checkoutId));
            });
        } catch (StaleCheckout $e) {
            // Someone else (the other close attempt, or a reply) changed the saga; their outcome stands.
            Log::info('Cart close lost a race; leaving the saga as it is', ['checkoutId' => $checkoutId, 'error' => $e->getMessage()]);
        }
    }
}
