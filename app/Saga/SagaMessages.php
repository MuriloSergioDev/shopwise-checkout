<?php

declare(strict_types=1);

namespace App\Saga;

use App\Enums\SagaStep;
use App\Models\Checkout;
use Illuminate\Support\Carbon;
use LogicException;
use Shopwise\Contracts\Commands\Inventory\CommitReservationV1;
use Shopwise\Contracts\Commands\Inventory\ReleaseReservationV1;
use Shopwise\Contracts\Commands\Inventory\ReserveStockV1;
use Shopwise\Contracts\Commands\Orders\CancelOrderV1;
use Shopwise\Contracts\Commands\Orders\PlaceOrderV1;
use Shopwise\Contracts\Commands\Payments\RefundPaymentV1;
use Shopwise\Contracts\Commands\Payments\RequestPaymentV1;
use Shopwise\Contracts\Events\Checkout\CompletedV1;
use Shopwise\Contracts\Events\Checkout\FailedV1;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\Outbox;

/**
 * Builds the saga's outgoing messages from the checkout row with the generated contract classes and records them in
 * the outbox (so they commit with the state change). Commands are keyed by checkoutId; their topics and schemas
 * belong to the receiver.
 */
final class SagaMessages
{
    /**
     * Records the command of a forward or undo step. Re-sending records the same data under a new envelope id.
     */
    public static function command(Checkout $checkout, SagaStep $step): Envelope
    {
        $requestedAt = self::now();

        $command = match ($step) {
            SagaStep::ReserveStock => new ReserveStockV1(
                checkoutId: $checkout->id,
                lines: self::stockLines($checkout),
                requestedAt: $requestedAt,
            ),
            SagaStep::RequestPayment => new RequestPaymentV1(
                checkoutId: $checkout->id,
                customerId: $checkout->customer_id,
                amount: $checkout->total,
                currency: $checkout->currency,
                paymentMethodId: $checkout->payment_method_id,
                requestedAt: $requestedAt,
            ),
            SagaStep::CommitReservation => new CommitReservationV1(
                checkoutId: $checkout->id,
                reservationId: $checkout->reservation_id ?? throw new LogicException('Committing without a reservation.'),
                requestedAt: $requestedAt,
            ),
            SagaStep::PlaceOrder => new PlaceOrderV1(
                checkoutId: $checkout->id,
                customerId: $checkout->customer_id,
                email: $checkout->customer_email,
                lines: $checkout->lines,
                currency: $checkout->currency,
                subtotal: $checkout->subtotal,
                discount: $checkout->discount,
                total: $checkout->total,
                couponCode: $checkout->coupon_code,
                paymentId: $checkout->payment_id ?? throw new LogicException('Placing an order without a payment.'),
                shippingAddress: $checkout->shipping_address,
                requestedAt: $requestedAt,
            ),
            SagaStep::ReleaseReservation => new ReleaseReservationV1(
                checkoutId: $checkout->id,
                requestedAt: $requestedAt,
            ),
            SagaStep::RefundPayment => new RefundPaymentV1(
                checkoutId: $checkout->id,
                reason: ($checkout->failure_reason ?? throw new LogicException('Refunding without a failure.'))->refundReason(),
                requestedAt: $requestedAt,
                // Payments refuses a refund whose values disagree with its payment.
                customerId: $checkout->customer_id,
                amount: $checkout->total,
                currency: $checkout->currency,
            ),
            SagaStep::CancelOrder => new CancelOrderV1(
                checkoutId: $checkout->id,
                reason: 'timeout',
                requestedAt: $requestedAt,
            ),
            default => throw new LogicException("Step {$step->value} has no command."),
        };

        return Outbox::record($command::TYPE, $checkout->id, $command->toArray(), $command::SCHEMA);
    }

    public static function completed(Checkout $checkout): Envelope
    {
        $event = new CompletedV1(
            checkoutId: $checkout->id,
            customerId: $checkout->customer_id,
            cartId: $checkout->cart_id,
            orderId: $checkout->order_id ?? throw new LogicException('Completing without an order.'),
            paymentId: $checkout->payment_id ?? throw new LogicException('Completing without a payment.'),
            currency: $checkout->currency,
            subtotal: $checkout->subtotal,
            discount: $checkout->discount,
            total: $checkout->total,
            couponCode: $checkout->coupon_code,
            completedAt: self::now(),
        );

        return Outbox::record(CompletedV1::TYPE, $checkout->id, $event->toArray(), CompletedV1::SCHEMA);
    }

    public static function failed(Checkout $checkout): Envelope
    {
        $event = new FailedV1(
            checkoutId: $checkout->id,
            customerId: $checkout->customer_id,
            cartId: $checkout->cart_id,
            reason: ($checkout->failure_reason ?? throw new LogicException('Failing without a reason.'))->value,
            failedAt: self::now(),
        );

        return Outbox::record(FailedV1::TYPE, $checkout->id, $event->toArray(), FailedV1::SCHEMA);
    }

    /**
     * @return list<array{sku: string, quantity: int}>
     */
    public static function stockLines(Checkout $checkout): array
    {
        return array_map(fn (array $line): array => ['sku' => $line['sku'], 'quantity' => $line['quantity']], $checkout->lines);
    }

    private static function now(): string
    {
        return Carbon::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
