<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a checkout saga is (ADR-0004). Each forward state waits for the reply to one command
 * (awaiting_payment_action waits on the same one as paying, while the customer does 3-D Secure);
 * completed and failed are final.
 */
enum CheckoutStatus: string
{
    case Reserving = 'reserving';
    case Paying = 'paying';
    case AwaitingPaymentAction = 'awaiting_payment_action';
    case CommittingStock = 'committing_stock';
    case PlacingOrder = 'placing_order';
    case ClosingCart = 'closing_cart';
    case Completed = 'completed';
    case Compensating = 'compensating';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }

    /**
     * The command a forward state waits on; null for states that wait on no reply.
     */
    public function awaitedStep(): ?SagaStep
    {
        return match ($this) {
            self::Reserving => SagaStep::ReserveStock,
            self::Paying, self::AwaitingPaymentAction => SagaStep::RequestPayment,
            self::CommittingStock => SagaStep::CommitReservation,
            self::PlacingOrder => SagaStep::PlaceOrder,
            default => null,
        };
    }
}
