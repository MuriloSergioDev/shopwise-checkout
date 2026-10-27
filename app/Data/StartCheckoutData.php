<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A validated POST /api/checkouts: who asks (from the JWT), with which key, for which cart.
 */
final readonly class StartCheckoutData
{
    /**
     * @param  array{name: string, line1: string, line2: string|null, city: string, region: string|null, postalCode: string, country: string}  $shippingAddress
     * @param  array<string, string>  $traceHeaders  the request's traceparent/tracestate
     */
    public function __construct(
        public string $customerId,
        public string $customerEmail,
        public string $bearerToken,
        public string $idempotencyKey,
        public string $cartId,
        public string $paymentMethodId,
        public array $shippingAddress,
        public array $traceHeaders,
    ) {}
}
