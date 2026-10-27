<?php

declare(strict_types=1);

namespace App\Clients;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;

/**
 * Consecutive-failure circuit breaker per dependency, kept in the cache so every worker and pod shares it.
 * After `breaker_threshold` failures in a row the circuit opens for `breaker_cooldown_seconds`: calls fail fast
 * without touching the network. The first call after the cooldown goes through; if it fails too the circuit
 * opens again straight away (the failure count is still over the threshold).
 */
final readonly class CircuitBreaker
{
    public function __construct(private Repository $cache) {}

    public function isOpen(string $service): bool
    {
        return $this->cache->has($this->openKey($service));
    }

    public function recordSuccess(string $service): void
    {
        $this->cache->forget($this->failuresKey($service));
    }

    public function recordFailure(string $service): void
    {
        $failures = (int) $this->cache->increment($this->failuresKey($service));

        if ($failures >= config()->integer('services.http.breaker_threshold')) {
            $cooldown = config()->integer('services.http.breaker_cooldown_seconds');
            $this->cache->put($this->openKey($service), true, $cooldown);
            Log::warning('Circuit opened', ['service' => $service, 'failures' => $failures, 'cooldown_seconds' => $cooldown]);
        }
    }

    private function failuresKey(string $service): string
    {
        return "circuit:{$service}:failures";
    }

    private function openKey(string $service): string
    {
        return "circuit:{$service}:open";
    }
}
