<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Cart or pricing did not answer usably (timeout, connection error, 5xx, open circuit). The BFF gets a 503 and
 * may retry with the same Idempotency-Key.
 */
final class DependencyUnavailable extends RuntimeException
{
    public function __construct(public readonly string $service, string $reason)
    {
        parent::__construct("{$service} is unavailable: {$reason}");
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => "The {$this->service} service is unavailable. Try again shortly."], 503, ['Retry-After' => '5']);
    }
}
