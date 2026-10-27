<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Messaging\Reply;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use Illuminate\Support\Carbon;
use Shopwise\Contracts\Events\Payments\PaymentRequiresActionV1;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

/**
 * payments.payment-requires-action: the card needs 3-D Secure; the checkout waits for the customer.
 */
final readonly class PaymentRequiresActionHandler implements MessageHandler
{
    public function __construct(private CheckoutSaga $saga) {}

    public function handle(Envelope $envelope): void
    {
        $reply = PaymentRequiresActionV1::fromArray($envelope->data);
        $checkout = Reply::checkout($reply->checkoutId, $envelope);

        if ($checkout instanceof Checkout) {
            $this->saga->paymentRequiresAction($checkout, $envelope, $reply->paymentId, $reply->clientSecret, Carbon::parse($reply->expiresAt));
        }
    }
}
