<?php

declare(strict_types=1);

namespace App\Clients;

use App\Exceptions\DependencyUnavailable;
use App\Saga\SagaTrace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Calls another Shopwise service over REST with the resilience every saga call needs: connect and total timeouts,
 * retries on connection errors and 5xx, a circuit breaker, and the current trace context in the headers.
 * Anything that is not an HTTP answer below 500 becomes DependencyUnavailable; 4xx answers are the caller's to map.
 */
final readonly class ServiceClient
{
    public function __construct(private Factory $http, private CircuitBreaker $breaker) {}

    /**
     * @param  'cart'|'pricing'  $service  key under config/services.php
     * @param  array<string, mixed>|null  $json
     */
    public function send(string $service, string $method, string $path, ?string $bearerToken = null, ?array $json = null): Response
    {
        if ($this->breaker->isOpen($service)) {
            throw new DependencyUnavailable($service, 'circuit open');
        }

        $request = $this->http
            ->baseUrl(config()->string("services.{$service}.base_url"))
            ->timeout(config()->float("services.{$service}.timeout_seconds"))
            ->connectTimeout(config()->float("services.{$service}.connect_timeout_seconds"))
            ->acceptJson()
            ->withHeaders(SagaTrace::headers())
            ->retry(
                1 + config()->integer("services.{$service}.retries"),
                config()->integer('services.http.retry_sleep_ms'),
                fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                throw: false,
            );

        if ($bearerToken !== null) {
            $request->withToken($bearerToken);
        }

        try {
            $response = $request->send($method, $path, $json === null ? [] : ['json' => $json]);
        } catch (ConnectionException $e) {
            $this->breaker->recordFailure($service);

            throw new DependencyUnavailable($service, $e->getMessage());
        }

        if ($response->serverError()) {
            $this->breaker->recordFailure($service);

            throw new DependencyUnavailable($service, "HTTP {$response->status()}");
        }

        $this->breaker->recordSuccess($service);

        return $response;
    }
}
