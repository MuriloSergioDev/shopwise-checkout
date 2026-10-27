<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Actions\CloseCheckoutCart;
use App\Enums\CheckoutStatus;
use App\Messaging\Reply;
use App\Models\Checkout;
use App\Saga\CheckoutSaga;
use Illuminate\Support\Facades\DB;
use Shopwise\Contracts\Events\Orders\OrderPlacedV1;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

/**
 * orders.order-placed: step 6 succeeded. Closing the cart is a REST call, so it runs after the reply's
 * transaction commits; checkout:time-out-steps retries it if this first attempt does not work.
 */
final readonly class OrderPlacedHandler implements MessageHandler
{
    public function __construct(private CheckoutSaga $saga, private CloseCheckoutCart $closeCart) {}

    public function handle(Envelope $envelope): void
    {
        $reply = OrderPlacedV1::fromArray($envelope->data);
        $checkout = Reply::checkout($reply->checkoutId, $envelope);

        if (! $checkout instanceof Checkout) {
            return;
        }

        $this->saga->orderPlaced($checkout, $envelope, $reply->orderId);

        if ($checkout->status === CheckoutStatus::ClosingCart) {
            $checkoutId = $checkout->id;
            DB::afterCommit(fn () => $this->closeCart->handle($checkoutId));
        }
    }
}
