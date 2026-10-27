<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Messaging\Reply;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

/**
 * orders.order-rejected: step 6 failed, refund and release.
 */
final readonly class OrderRejectedHandler implements MessageHandler
{
    public function __construct(private CheckoutSaga $saga) {}

    public function handle(Envelope $envelope): void
    {
        $reply = new Reply($envelope);
        $checkout = $reply->checkout();

        if ($checkout instanceof Checkout) {
            $this->saga->orderRejected($checkout, $envelope, $reply->string('reason'), $reply->nullableString('message'));
        }
    }
}
