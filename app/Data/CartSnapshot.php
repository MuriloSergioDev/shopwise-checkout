<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Cart's `CartSnapshot` (shopwise-contracts/openapi/cart.yaml): what step 1 of the saga reads.
 */
final readonly class CartSnapshot
{
    /**
     * @param  list<CartSnapshotLine>  $lines
     */
    public function __construct(
        public string $cartId,
        public string $customerId,
        public int $version,
        public ?string $couponCode,
        public array $lines,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $lines = [];
        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (is_array($line)) {
                $lines[] = new CartSnapshotLine(
                    sku: is_string($line['sku'] ?? null) ? $line['sku'] : '',
                    quantity: is_int($line['quantity'] ?? null) ? $line['quantity'] : 0,
                    productName: is_string($line['productName'] ?? null) ? $line['productName'] : null,
                    variantName: is_string($line['variantName'] ?? null) ? $line['variantName'] : '',
                    available: ($line['available'] ?? false) === true,
                );
            }
        }

        return new self(
            cartId: is_string($data['cartId'] ?? null) ? $data['cartId'] : '',
            customerId: is_string($data['customerId'] ?? null) ? $data['customerId'] : '',
            version: is_int($data['version'] ?? null) ? $data['version'] : 0,
            couponCode: is_string($data['couponCode'] ?? null) ? $data['couponCode'] : null,
            lines: $lines,
        );
    }
}
