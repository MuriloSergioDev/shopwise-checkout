<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StartCheckout;
use App\Http\Requests\StartCheckoutRequest;
use App\Http\Resources\CheckoutResource;
use Illuminate\Http\JsonResponse;

final class StartCheckoutController
{
    public function __invoke(StartCheckoutRequest $request, StartCheckout $startCheckout): JsonResponse
    {
        $started = $startCheckout->handle($request->toData());

        $response = CheckoutResource::make($started->checkout)->forCustomer()->response();

        return $started->created
            ? $response->setStatusCode(202)->header('Location', "/api/checkouts/{$started->checkout->id}")
            : $response->setStatusCode(200);
    }
}
