<?php

declare(strict_types=1);

namespace App\Actions;

use App\Clients\CartClient;
use App\Enums\CheckoutStatus;
use App\Exceptions\DependencyUnavailable;
use App\Exceptions\StaleCheckout;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use App\Saga\SagaTrace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gives a failed checkout's cart back to the customer over REST (ADR-0004 "Cart lock"). Like step 7 it runs after
 * the failing transaction commits, outside any transaction, never throws, and is retried with backoff by
 * checkout:time-out-steps until cart answers.
 */
final readonly class UnlockCheckoutCart
{
    public function __construct(private CartClient $cart, private CheckoutSaga $saga) {}

    public function handle(string $checkoutId): void
    {
        $checkout = Checkout::query()->find($checkoutId);
        if (! $checkout instanceof Checkout || $checkout->status !== CheckoutStatus::Failed || $checkout->cart_unlocked_at !== null) {
            return;
        }

        SagaTrace::forCheckout($checkout, 'checkout unlock cart', function () use ($checkout): void {
            try {
                $unlocked = $this->cart->unlock($checkout->cart_id, $checkout->id);
                $this->transition($checkout->id, fn (Checkout $fresh) => $this->saga->cartUnlocked($fresh, $unlocked));
            } catch (DependencyUnavailable $e) {
                $this->transition($checkout->id, fn (Checkout $fresh) => $this->saga->cartUnlockFailed($fresh, $e->getMessage()));
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
            Log::info('Cart unlock lost a race; leaving the saga as it is', ['checkoutId' => $checkoutId, 'error' => $e->getMessage()]);
        }
    }
}
