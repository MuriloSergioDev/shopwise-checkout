<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Models\Checkout;
use Illuminate\Support\Facades\Log;
use Shopwise\Kit\Messaging\Envelope;

/**
 * Finds the checkout a saga reply is about. Handlers parse the reply with its generated contract class first.
 */
final class Reply
{
    /**
     * Read without a lock (transitions check the row's version instead). Null, logged, when the checkout is unknown.
     */
    public static function checkout(string $checkoutId, Envelope $envelope): ?Checkout
    {
        $checkout = Checkout::query()->find($checkoutId);

        if (! $checkout instanceof Checkout) {
            Log::warning('Reply for an unknown checkout', ['type' => $envelope->type, 'id' => $envelope->id, 'checkoutId' => $checkoutId]);
        }

        return $checkout;
    }
}
