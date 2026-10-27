<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Messaging\Reply;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use Illuminate\Support\Carbon;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

/**
 * inventory.stock-reserved: step 3 succeeded, go on to payment.
 */
final readonly class StockReservedHandler implements MessageHandler
{
    public function __construct(private CheckoutSaga $saga) {}

    public function handle(Envelope $envelope): void
    {
        $reply = new Reply($envelope);
        $checkout = $reply->checkout();

        if ($checkout instanceof Checkout) {
            $this->saga->stockReserved($checkout, $envelope, $reply->string('reservationId'), Carbon::parse($reply->string('expiresAt')));
        }
    }
}
