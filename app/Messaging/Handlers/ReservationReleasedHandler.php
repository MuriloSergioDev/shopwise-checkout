<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Messaging\Reply;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
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
        $reply = new Reply($envelope);
        $checkout = $reply->checkout();

        if ($checkout instanceof Checkout) {
            $this->saga->reservationReleased($checkout, $envelope, $reply->string('reason'));
        }
    }
}
