<?php

declare(strict_types=1);

namespace App\Actions;

use App\Clients\CartClient;
use App\Clients\PricingClient;
use App\Data\CartSnapshot;
use App\Data\CartSnapshotLine;
use App\Data\Quote;
use App\Data\StartCheckoutData;
use App\Data\StartedCheckout;
use App\Enums\CheckoutStatus;
use App\Exceptions\CartLocked;
use App\Exceptions\DependencyUnavailable;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use App\Saga\SagaTrace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * POST /api/checkouts: steps 1 and 2 of ADR-0004 run here, synchronously and with the caller's token, so a bad
 * cart or coupon is a plain 422 and no saga starts. Step 1 locks the cart for the new checkout id; whenever no saga
 * starts after that, the cart is unlocked again. Then the saga row and the first command commit together.
 */
final readonly class StartCheckout
{
    public function __construct(
        private CartClient $cart,
        private PricingClient $pricing,
        private CheckoutSaga $saga,
    ) {}

    public function handle(StartCheckoutData $data): StartedCheckout
    {
        return SagaTrace::forRequest($data->traceHeaders, 'checkout start', function () use ($data): StartedCheckout {
            $existing = $this->findByKey($data);
            if ($existing instanceof Checkout) {
                return new StartedCheckout($existing, false);
            }

            $this->assertNoCheckoutInProgress($data->cartId);

            // The id exists before the saga row so step 1 can lock the cart for it.
            $checkoutId = (new Checkout)->newUniqueId();

            try {
                $snapshot = $this->cart->snapshot($data->cartId, $checkoutId, $data->bearerToken);
            } catch (CartLocked) {
                // A concurrent request with the same key may hold the lock for its own checkout.
                $existing = $this->findByKey($data);
                if ($existing instanceof Checkout) {
                    return new StartedCheckout($existing, false);
                }

                throw new ConflictHttpException('The cart is locked by another checkout.');
            }

            if (! $snapshot instanceof CartSnapshot) {
                throw new NotFoundHttpException('Unknown cart.');
            }

            try {
                $checkout = $this->startSaga($checkoutId, $data, $snapshot);
            } catch (Throwable $e) {
                // No saga took over the lock (422, pricing down, a lost race): give the cart back right away.
                $this->unlock($data->cartId, $checkoutId);

                throw $e;
            }

            if (! $checkout instanceof Checkout) {
                // A concurrent request with the same key created its checkout first.
                $this->unlock($data->cartId, $checkoutId);

                return new StartedCheckout($this->findByKey($data) ?? throw new ConflictHttpException('The cart already has a checkout in progress.'), false);
            }

            return new StartedCheckout($checkout, true);
        });
    }

    /**
     * Steps 1 (checks) and 2, then the row and the first command in one transaction. Null when a concurrent request
     * with the same Idempotency-Key created its checkout first.
     */
    private function startSaga(string $checkoutId, StartCheckoutData $data, CartSnapshot $snapshot): ?Checkout
    {
        if ($snapshot->customerId !== $data->customerId) {
            throw new NotFoundHttpException('Unknown cart.');
        }

        $this->assertCheckoutable($snapshot);

        $stockLines = array_map(fn (CartSnapshotLine $line): array => ['sku' => $line->sku, 'quantity' => $line->quantity], $snapshot->lines);
        $quote = $this->pricing->quote($stockLines, $snapshot->couponCode, $data->bearerToken);
        $this->assertPriced($quote, $snapshot);

        try {
            return DB::transaction(function () use ($checkoutId, $data, $snapshot, $quote): Checkout {
                $this->assertNoCheckoutInProgress($data->cartId);
                $checkout = $this->create($checkoutId, $data, $snapshot, $quote);
                $this->saga->begin($checkout);

                return $checkout;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request won the race: the same key returns its checkout, another key is a conflict.
            if ($this->findByKey($data) instanceof Checkout) {
                return null;
            }

            throw new ConflictHttpException('The cart already has a checkout in progress.', $e);
        }
    }

    /**
     * Best effort: a lock left behind keeps the cart read-only until someone unlocks it, so it is logged loudly.
     */
    private function unlock(string $cartId, string $checkoutId): void
    {
        try {
            if (! $this->cart->unlock($cartId, $checkoutId)) {
                Log::warning('Cart refused to unlock after an aborted checkout start', ['cartId' => $cartId, 'checkoutId' => $checkoutId]);
            }
        } catch (DependencyUnavailable $e) {
            Log::error('Cart left locked by an aborted checkout start', ['cartId' => $cartId, 'checkoutId' => $checkoutId, 'error' => $e->getMessage()]);
        }
    }

    private function findByKey(StartCheckoutData $data): ?Checkout
    {
        return Checkout::query()
            ->where('customer_id', $data->customerId)
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();
    }

    private function assertNoCheckoutInProgress(string $cartId): void
    {
        if (Checkout::query()->where('active_cart_id', $cartId)->exists()) {
            throw new ConflictHttpException('The cart already has a checkout in progress.');
        }
    }

    private function assertCheckoutable(CartSnapshot $snapshot): void
    {
        if ($snapshot->lines === []) {
            throw ValidationException::withMessages(['cartId' => 'The cart is empty.']);
        }

        $unavailable = array_values(array_map(
            fn (CartSnapshotLine $line): string => $line->sku,
            array_filter($snapshot->lines, fn (CartSnapshotLine $line): bool => ! $line->available),
        ));
        if ($unavailable !== []) {
            throw ValidationException::withMessages(['cartId' => 'No longer for sale: '.implode(', ', $unavailable).'.']);
        }
    }

    private function assertPriced(Quote $quote, CartSnapshot $snapshot): void
    {
        $unpriced = [];
        foreach ($snapshot->lines as $line) {
            if (($quote->linesBySku[$line->sku]['lineAmount'] ?? null) === null) {
                $unpriced[] = $line->sku;
            }
        }

        if ($unpriced !== []) {
            throw ValidationException::withMessages(['lines' => 'No price for: '.implode(', ', $unpriced).'.']);
        }

        if ($quote->coupon !== null && ! $quote->coupon['valid']) {
            throw ValidationException::withMessages(['couponCode' => $quote->coupon['reason'] ?? 'unknown']);
        }

        if ($quote->total < 1) {
            throw ValidationException::withMessages(['cartId' => 'Nothing to pay.']);
        }
    }

    private function create(string $checkoutId, StartCheckoutData $data, CartSnapshot $snapshot, Quote $quote): Checkout
    {
        $lines = array_map(fn (CartSnapshotLine $line): array => [
            'sku' => $line->sku,
            'productName' => $line->productName,
            'variantName' => $line->variantName,
            'quantity' => $line->quantity,
            'unitAmount' => (int) $quote->linesBySku[$line->sku]['unitAmount'],
            'lineAmount' => (int) $quote->linesBySku[$line->sku]['lineAmount'],
        ], $snapshot->lines);

        return Checkout::query()->create([
            'id' => $checkoutId,
            'customer_id' => $data->customerId,
            'customer_email' => $data->customerEmail,
            'idempotency_key' => $data->idempotencyKey,
            'cart_id' => $snapshot->cartId,
            'cart_version' => $snapshot->version,
            'active_cart_id' => $snapshot->cartId,
            'status' => CheckoutStatus::Reserving,
            'lines' => $lines,
            'currency' => $quote->currency,
            'subtotal' => $quote->subtotal,
            'discount' => $quote->discount,
            'total' => $quote->total,
            // Only a coupon pricing applied is part of the checkout (and redeemed on checkout.completed).
            'coupon_code' => $quote->coupon !== null ? $quote->coupon['code'] : null,
            'payment_method_id' => $data->paymentMethodId,
            'shipping_address' => $data->shippingAddress,
            'trace_context' => SagaTrace::headers(),
            'step_attempts' => 0,
            'version' => 0,
        ]);
    }
}
