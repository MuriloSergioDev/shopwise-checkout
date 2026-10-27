<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Messaging\Reply;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

/**
 * payments.payment-failed: step 4 failed, or (while compensating) the payment is confirmed undone.
 */
final readonly class PaymentFailedHandler implements MessageHandler
{
    public function __construct(private CheckoutSaga $saga) {}

    public function handle(Envelope $envelope): void
    {
        $reply = new Reply($envelope);
        $checkout = $reply->checkout();

        if ($checkout instanceof Checkout) {
            $this->saga->paymentFailed($checkout, $envelope, $reply->string('reason'), $reply->nullableString('message'));
        }
    }
}
