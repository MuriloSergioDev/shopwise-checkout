<?php

declare(strict_types=1);

namespace App\Clients;

use App\Data\CartSnapshot;
use App\Exceptions\DependencyUnavailable;
use Illuminate\Auth\AuthenticationException;

/**
 * Cart's REST API as the saga uses it (shopwise-contracts/openapi/cart.yaml): step 1 (snapshot, with the
 * customer's token) and step 7 (close the cart, cluster-internal, no token).
 */
final readonly class CartClient
{
    public function __construct(private ServiceClient $client) {}

    /**
     * The caller's cart snapshot, or null when cart answers 404 (unknown, closed, a guest cart or someone else's).
     *
     * @throws AuthenticationException when cart rejects the token
     * @throws DependencyUnavailable
     */
    public function snapshot(string $cartId, string $bearerToken): ?CartSnapshot
    {
        $response = $this->client->send('cart', 'GET', '/api/carts/'.rawurlencode($cartId).'/snapshot', $bearerToken);

        return match (true) {
            $response->notFound() => null,
            $response->unauthorized() => throw new AuthenticationException,
            $response->successful() && is_array($response->json('data')) => CartSnapshot::fromArray($response->json('data')),
            default => throw new DependencyUnavailable('cart', "unexpected HTTP {$response->status()} on snapshot"),
        };
    }

    /**
     * Closes the cart for the checkout. True when it is closed (now or earlier for this checkout); false when cart
     * answered with a permanent refusal (unknown cart, closed by another checkout, rejected body) that no retry fixes.
     *
     * @param  list<array{sku: string, quantity: int}>  $lines
     *
     * @throws DependencyUnavailable on anything worth retrying
     */
    public function markCheckedOut(string $cartId, string $checkoutId, string $orderId, array $lines): bool
    {
        $response = $this->client->send('cart', 'POST', '/internal/carts/'.rawurlencode($cartId).'/checked-out', null, [
            'checkoutId' => $checkoutId,
            'orderId' => $orderId,
            'lines' => $lines,
        ]);

        return match (true) {
            $response->successful() => true,
            in_array($response->status(), [404, 409, 422], true) => false,
            default => throw new DependencyUnavailable('cart', "unexpected HTTP {$response->status()} on checked-out"),
        };
    }
}
