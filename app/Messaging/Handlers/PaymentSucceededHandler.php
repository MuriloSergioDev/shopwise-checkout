<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Messaging\Reply;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

/**
 * payments.payment-succeeded: step 4 succeeded, commit the stock.
 */
final readonly class PaymentSucceededHandler implements MessageHandler
{
    public function __construct(private CheckoutSaga $saga) {}

    public function handle(Envelope $envelope): void
    {
        $reply = new Reply($envelope);
        $checkout = $reply->checkout();

        if ($checkout instanceof Checkout) {
            $this->saga->paymentSucceeded($checkout, $envelope, $reply->string('paymentId'));
        }
    }
}
