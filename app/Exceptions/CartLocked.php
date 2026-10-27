<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Cart answered the snapshot with 409 cart_locked: another checkout holds the cart's lock (ADR-0004 "Cart lock").
 */
final class CartLocked extends RuntimeException
{
    public function __construct(public readonly ?string $lockedByCheckoutId)
    {
        parent::__construct('The cart is locked by another checkout.');
    }
}
