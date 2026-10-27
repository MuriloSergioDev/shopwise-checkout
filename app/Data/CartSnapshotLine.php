<?php

declare(strict_types=1);

namespace App\Data;

final readonly class CartSnapshotLine
{
    public function __construct(
        public string $sku,
        public int $quantity,
        public ?string $productName,
        public string $variantName,
        public bool $available,
    ) {}
}
