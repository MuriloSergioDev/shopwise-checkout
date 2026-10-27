<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Messaging\Reply;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use Shopwise\Contracts\Events\Inventory\ReservationReleasedV1;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

/**
 * inventory.reservation-released: the reservation expired (fails step 5) or a requested release is confirmed.
 */
final readonly class ReservationReleasedHandler implements MessageHandler
{
    public function __construct(private CheckoutSaga $saga) {}

    public function handle(Envelope $envelope): void
    {
        $reply = ReservationReleasedV1::fromArray($envelope->data);
        $checkout = Reply::checkout($reply->checkoutId, $envelope);

        if ($checkout instanceof Checkout) {
            $this->saga->reservationReleased($checkout, $envelope, $reply->reason);
        }
    }
}
