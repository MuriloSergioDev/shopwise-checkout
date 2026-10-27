<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Pricing's `Quote` (shopwise-contracts/openapi/pricing.yaml): what step 2 of the saga reads.
 */
final readonly class Quote
{
    /**
     * @param  array<string, array{unitAmount: int|null, lineAmount: int|null}>  $linesBySku
     * @param  array{code: string, valid: bool, reason: string|null}|null  $coupon
     */
    public function __construct(
        public string $currency,
        public array $linesBySku,
        public int $subtotal,
        public int $discount,
        public int $total,
        public ?array $coupon,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $linesBySku = [];
        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (is_array($line) && is_string($line['sku'] ?? null)) {
                $linesBySku[$line['sku']] = [
                    'unitAmount' => is_int($line['unitAmount'] ?? null) ? $line['unitAmount'] : null,
                    'lineAmount' => is_int($line['lineAmount'] ?? null) ? $line['lineAmount'] : null,
                ];
            }
        }

        $coupon = $data['coupon'] ?? null;

        return new self(
            currency: is_string($data['currency'] ?? null) ? $data['currency'] : '',
            linesBySku: $linesBySku,
            subtotal: is_int($data['subtotal'] ?? null) ? $data['subtotal'] : 0,
            discount: is_int($data['discount'] ?? null) ? $data['discount'] : 0,
            total: is_int($data['total'] ?? null) ? $data['total'] : 0,
            coupon: is_array($coupon) ? [
                'code' => is_string($coupon['code'] ?? null) ? $coupon['code'] : '',
                'valid' => ($coupon['valid'] ?? false) === true,
                'reason' => is_string($coupon['reason'] ?? null) ? $coupon['reason'] : null,
            ] : null,
        );
    }
}
