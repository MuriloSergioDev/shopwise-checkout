<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CheckoutStatus;
use App\Exceptions\StaleCheckout;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use App\Saga\SagaTrace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One pass over sagas whose next_attempt_at has passed: re-sends late commands, times steps out into their
 * compensation, re-sends unconfirmed undo commands and retries closing (or, after a failure, unlocking) carts.
 * Each saga is its own transaction; one that changed meanwhile (optimistic lock) is left for the next pass.
 */
final readonly class TimeOutCheckoutSteps
{
    public function __construct(
        private CheckoutSaga $saga,
        private CloseCheckoutCart $closeCart,
        private UnlockCheckoutCart $unlockCart,
    ) {}

    /**
     * @return int how many sagas were looked at
     */
    public function handle(): int
    {
        /** @var list<string> $ids */
        $ids = Checkout::query()
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', Carbon::now())
            ->where('status', '!=', CheckoutStatus::Completed)
            ->orderBy('next_attempt_at')
            ->limit(config()->integer('checkout.deadline_batch_size'))
            ->pluck('id')
            ->all();

        foreach ($ids as $id) {
            $checkout = Checkout::query()->find($id);
            if (! $checkout instanceof Checkout) {
                continue;
            }

            if ($checkout->status === CheckoutStatus::ClosingCart) {
                $this->closeCart->handle($checkout->id);

                continue;
            }

            // A failed checkout is only due while its cart is still locked.
            if ($checkout->status === CheckoutStatus::Failed) {
                $this->unlockCart->handle($checkout->id);

                continue;
            }

            try {
                SagaTrace::forCheckout($checkout, 'checkout step deadline', function () use ($id): void {
                    DB::transaction(function () use ($id): void {
                        $this->saga->deadlinePassed(Checkout::query()->findOrFail($id));
                    });
                });
            } catch (StaleCheckout $e) {
                Log::info('Saga changed while timing it out; retrying on the next pass', ['checkoutId' => $id, 'error' => $e->getMessage()]);
            }
        }

        return count($ids);
    }
}
