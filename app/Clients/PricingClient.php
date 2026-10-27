<?php

declare(strict_types=1);

namespace App\Clients;

use App\Data\Quote;
use App\Exceptions\DependencyUnavailable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;

/**
 * Pricing's quote endpoint (shopwise-contracts/openapi/pricing.yaml), step 2 of the saga. Always sent with the
 * customer's token so pricing checks the coupon's redemption limits.
 */
final readonly class PricingClient
{
    public function __construct(private ServiceClient $client) {}

    /**
     * @param  list<array{sku: string, quantity: int}>  $lines
     *
     * @throws AuthenticationException when pricing rejects the token
     * @throws ValidationException when pricing rejects the lines
     * @throws DependencyUnavailable
     */
    public function quote(array $lines, ?string $couponCode, string $bearerToken): Quote
    {
        $body = ['lines' => $lines];
        if ($couponCode !== null) {
            $body['couponCode'] = $couponCode;
        }

        $response = $this->client->send('pricing', 'POST', '/api/quotes', $bearerToken, $body);

        return match (true) {
            $response->successful() && is_array($response->json('data')) => Quote::fromArray($response->json('data')),
            $response->unauthorized() => throw new AuthenticationException,
            $response->unprocessableEntity() => throw ValidationException::withMessages(['lines' => 'Pricing could not quote the cart lines.']),
            default => throw new DependencyUnavailable('pricing', "unexpected HTTP {$response->status()} on quote"),
        };
    }
}
