<?php

declare(strict_types=1);

namespace App\Clients;

use App\Data\CartSnapshot;
use App\Exceptions\CartLocked;
use App\Exceptions\DependencyUnavailable;
use Illuminate\Auth\AuthenticationException;

/**
 * Cart's REST API as the saga uses it (shopwise-contracts/openapi/cart.yaml): step 1 (snapshot + lock, with the
 * customer's token), step 7 (close the cart) and unlocking it after a failure (both cluster-internal, no token).
 */
final readonly class CartClient
{
    public function __construct(private ServiceClient $client) {}

    /**
     * Step 1: the caller's cart snapshot, taken together with the cart's lock for the checkout (the same checkoutId
     * may take it again). Null when cart answers 404 (unknown, closed, a guest cart or someone else's).
     *
     * @throws CartLocked when another checkout holds the lock
     * @throws AuthenticationException when cart rejects the token
     * @throws DependencyUnavailable
     */
    public function snapshot(string $cartId, string $checkoutId, string $bearerToken): ?CartSnapshot
    {
        $response = $this->client->send('cart', 'POST', '/api/carts/'.rawurlencode($cartId).'/snapshot', $bearerToken, [
            'checkoutId' => $checkoutId,
        ]);

        return match (true) {
            $response->notFound() => null,
            $response->unauthorized() => throw new AuthenticationException,
            $response->conflict() => throw new CartLocked(is_string($response->json('checkoutId')) ? $response->json('checkoutId') : null),
            $response->successful() && is_array($response->json('data')) => CartSnapshot::fromArray($response->json('data')),
            default => throw new DependencyUnavailable('cart', "unexpected HTTP {$response->status()} on snapshot"),
        };
    }

    /**
     * Gives the cart back to the customer after a checkout that locked it did not go through. True when the lock is
     * gone (or was never this checkout's: cart changes nothing then); false when cart refused for good (404 unknown
     * or closed cart, 422) and no retry fixes it.
     *
     * @throws DependencyUnavailable on anything worth retrying
     */
    public function unlock(string $cartId, string $checkoutId): bool
    {
        $response = $this->client->send('cart', 'POST', '/internal/carts/'.rawurlencode($cartId).'/unlock', null, [
            'checkoutId' => $checkoutId,
        ]);

        return match (true) {
            $response->successful() => true,
            in_array($response->status(), [404, 422], true) => false,
            default => throw new DependencyUnavailable('cart', "unexpected HTTP {$response->status()} on unlock"),
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
