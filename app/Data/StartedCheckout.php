<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Checkout;

/**
 * StartCheckout's result: the checkout, and whether this request created it (202) or found it by key (200).
 */
final readonly class StartedCheckout
{
    public function __construct(public Checkout $checkout, public bool $created) {}
}
