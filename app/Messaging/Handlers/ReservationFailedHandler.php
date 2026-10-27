<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Messaging\Reply;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

/**
 * inventory.reservation-failed: nothing was held, the checkout fails as out_of_stock.
 */
final readonly class ReservationFailedHandler implements MessageHandler
{
    public function __construct(private CheckoutSaga $saga) {}

    public function handle(Envelope $envelope): void
    {
        $reply = new Reply($envelope);
        $checkout = $reply->checkout();

        if ($checkout instanceof Checkout) {
            $this->saga->reservationFailed($checkout, $envelope, $reply->string('reason'), $reply->list('lines'));
        }
    }
}
