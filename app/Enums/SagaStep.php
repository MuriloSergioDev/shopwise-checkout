<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A step of the saga as recorded in checkout_steps: the REST calls, the forward commands and the undo commands
 * (unlocking the cart of a failed checkout is a REST call, like closing it).
 */
enum SagaStep: string
{
    case Start = 'start';
    case ReserveStock = 'reserve_stock';
    case RequestPayment = 'request_payment';
    case CommitReservation = 'commit_reservation';
    case PlaceOrder = 'place_order';
    case CloseCart = 'close_cart';
    case ReleaseReservation = 'release_reservation';
    case RefundPayment = 'refund_payment';
    case CancelOrder = 'cancel_order';
    case UnlockCart = 'unlock_cart';
    case Finish = 'finish';
}
