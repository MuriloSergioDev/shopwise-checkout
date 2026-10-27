<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Messaging\Reply;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

/**
 * payments.refunded: a requested refund is confirmed.
 */
final readonly class RefundedHandler implements MessageHandler
{
    public function __construct(private CheckoutSaga $saga) {}

    public function handle(Envelope $envelope): void
    {
        $checkout = new Reply($envelope)->checkout();

        if ($checkout instanceof Checkout) {
            $this->saga->refunded($checkout, $envelope);
        }
    }
}
