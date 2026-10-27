<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CheckoutStatus;
use App\Http\Requests\ListCheckoutsRequest;
use App\Http\Resources\CheckoutResource;
use App\Models\Checkout;
use Illuminate\Http\JsonResponse;

/**
 * Admin view of recent sagas, newest first; stuck ones stay in compensating or a forward step.
 */
final class ListCheckoutsController
{
    public function __invoke(ListCheckoutsRequest $request): JsonResponse
    {
        $status = $request->status();

        $checkouts = Checkout::query()
            ->when($status instanceof CheckoutStatus, fn ($query) => $query->where('status', $status))
            ->latest()
            ->orderByDesc('id')
            ->paginate(perPage: $request->perPage(), page: $request->page());

        return new JsonResponse([
            'data' => CheckoutResource::collection($checkouts->getCollection())->resolve($request),
            'meta' => [
                'page' => $checkouts->currentPage(),
                'perPage' => $checkouts->perPage(),
                'total' => $checkouts->total(),
                'lastPage' => $checkouts->lastPage(),
            ],
        ]);
    }
}
