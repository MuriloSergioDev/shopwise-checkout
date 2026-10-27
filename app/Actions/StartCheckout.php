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
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use App\Saga\SagaTrace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /api/checkouts: steps 1 and 2 of ADR-0004 run here, synchronously and with the caller's token, so a bad
 * cart or coupon is a plain 422 and no saga starts. Then the saga row and the first command commit together.
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

            $snapshot = $this->cart->snapshot($data->cartId, $data->bearerToken);
            if (! $snapshot instanceof CartSnapshot || $snapshot->customerId !== $data->customerId) {
                throw new NotFoundHttpException('Unknown cart.');
            }

            $this->assertCheckoutable($snapshot);

            $stockLines = array_map(fn (CartSnapshotLine $line): array => ['sku' => $line->sku, 'quantity' => $line->quantity], $snapshot->lines);
            $quote = $this->pricing->quote($stockLines, $snapshot->couponCode, $data->bearerToken);
            $this->assertPriced($quote, $snapshot);

            try {
                $checkout = DB::transaction(function () use ($data, $snapshot, $quote): Checkout {
                    $this->assertNoCheckoutInProgress($data->cartId);
                    $checkout = $this->create($data, $snapshot, $quote);
                    $this->saga->begin($checkout);

                    return $checkout;
                });
            } catch (UniqueConstraintViolationException) {
                // A concurrent request won the race: the same key returns its checkout, another key is a conflict.
                $existing = $this->findByKey($data);
                if ($existing instanceof Checkout) {
                    return new StartedCheckout($existing, false);
                }

                throw new ConflictHttpException('The cart already has a checkout in progress.');
            }

            return new StartedCheckout($checkout, true);
        });
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

    private function create(StartCheckoutData $data, CartSnapshot $snapshot, Quote $quote): Checkout
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
