<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a checkout failed (checkout.failed `reason`, API `failureReason`).
 */
enum FailureReason: string
{
    case OutOfStock = 'out_of_stock';
    case PaymentFailed = 'payment_failed';
    case ReservationExpired = 'reservation_expired';
    case OrderRejected = 'order_rejected';
    case Timeout = 'timeout';

    /**
     * The `reason` of payments.refund-payment, which only knows the failures that can follow a payment.
     */
    public function refundReason(): string
    {
        return match ($this) {
            self::ReservationExpired, self::OrderRejected => $this->value,
            default => self::Timeout->value,
        };
    }
}
