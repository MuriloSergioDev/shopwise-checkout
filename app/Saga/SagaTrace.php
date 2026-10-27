<?php

declare(strict_types=1);

namespace App\Saga;

use App\Models\Checkout;
use Closure;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Shopwise\Kit\Messaging\Tracing;
use Throwable;

/**
 * Keeps one checkout in one trace. The checkout's span context is stored on the saga row when it starts; work that
 * runs outside a reply's consumer span (timeouts, cart closing from the scheduler) continues that stored trace, so
 * the outbox (Tracing::currentHeaders) and the REST calls (headers()) carry it on.
 */
final class SagaTrace
{
    /**
     * Runs $work in a span that continues the incoming request's trace: the current server span when the
     * auto-instrumentation made one, else the request's own traceparent header.
     *
     * @template T
     *
     * @param  array<string, string>  $requestHeaders
     * @param  non-empty-string  $name
     * @param  Closure(): T  $work
     * @return T
     */
    public static function forRequest(array $requestHeaders, string $name, Closure $work): mixed
    {
        $current = Context::getCurrent();
        $parent = Span::fromContext($current)->getContext()->isValid()
            ? $current
            : TraceContextPropagator::getInstance()->extract($requestHeaders);

        return self::run($parent, $name, $work);
    }

    /**
     * Runs $work in a span under the trace stored on the checkout (a new trace when it has none).
     *
     * @template T
     *
     * @param  non-empty-string  $name
     * @param  Closure(): T  $work
     * @return T
     */
    public static function forCheckout(Checkout $checkout, string $name, Closure $work): mixed
    {
        return self::run(TraceContextPropagator::getInstance()->extract($checkout->trace_context ?? []), $name, $work);
    }

    /**
     * traceparent/tracestate of the active span, for outgoing HTTP requests and for storing on the saga row.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return Tracing::currentHeaders();
    }

    /**
     * @template T
     *
     * @param  non-empty-string  $name
     * @param  Closure(): T  $work
     * @return T
     */
    private static function run(ContextInterface $parent, string $name, Closure $work): mixed
    {
        $span = Globals::tracerProvider()->getTracer('shopwise-checkout')
            ->spanBuilder($name)
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();
        $scope = $span->storeInContext($parent)->activate();

        try {
            return $work();
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
