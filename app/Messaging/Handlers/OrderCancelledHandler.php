<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Messaging\Reply;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use Shopwise\Contracts\Events\Orders\OrderCancelledV1;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

/**
 * orders.order-cancelled: a requested cancel-order is confirmed.
 */
final readonly class OrderCancelledHandler implements MessageHandler
{
    public function __construct(private CheckoutSaga $saga) {}

    public function handle(Envelope $envelope): void
    {
        $reply = OrderCancelledV1::fromArray($envelope->data);
        $checkout = Reply::checkout($reply->checkoutId, $envelope);

        if ($checkout instanceof Checkout) {
            $this->saga->orderCancelled($checkout, $envelope, $reply->orderId);
        }
    }
}
