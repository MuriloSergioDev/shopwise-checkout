<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\CheckoutResource;
use App\Models\Checkout;
use Illuminate\Http\Request;
use Shopwise\Kit\Auth\JwtUser;

/**
 * The storefront BFF polls this until status is completed or failed. Another customer's checkout is 404.
 */
final class ShowCheckoutController
{
    public function __invoke(Request $request, string $checkoutId): CheckoutResource
    {
        /** @var JwtUser $user */
        $user = $request->user();

        $checkout = Checkout::query()
            ->whereKey($checkoutId)
            ->where('customer_id', $user->id)
            ->firstOrFail();

        return CheckoutResource::make($checkout)->forCustomer();
    }
}
