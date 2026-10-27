<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Messaging\Reply;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use Shopwise\Contracts\Events\Payments\RefundedV1;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

/**
 * payments.refunded: a requested refund is confirmed (an `external` one is ignored).
 */
final readonly class RefundedHandler implements MessageHandler
{
    public function __construct(private CheckoutSaga $saga) {}

    public function handle(Envelope $envelope): void
    {
        $reply = RefundedV1::fromArray($envelope->data);
        $checkout = Reply::checkout($reply->checkoutId, $envelope);

        if ($checkout instanceof Checkout) {
            $this->saga->refunded($checkout, $envelope, $reply->reason);
        }
    }
}
