<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CheckoutStatus;
use App\Models\Checkout;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A checkout waiting for its stock reservation, as StartCheckout leaves it (without the outbox row).
 *
 * @extends Factory<Checkout>
 */
final class CheckoutFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cartId = (string) Str::ulid();

        return [
            'customer_id' => (string) Str::ulid(),
            'customer_email' => fake()->safeEmail(),
            'idempotency_key' => (string) Str::uuid(),
            'cart_id' => $cartId,
            'cart_version' => 3,
            'active_cart_id' => $cartId,
            'status' => CheckoutStatus::Reserving,
            'lines' => [
                ['sku' => 'TEE-MER-BLA-M', 'productName' => 'Merino Tee', 'variantName' => 'Black / M', 'quantity' => 2, 'unitAmount' => 2900, 'lineAmount' => 5800],
            ],
            'currency' => 'USD',
            'subtotal' => 5800,
            'discount' => 0,
            'total' => 5800,
            'coupon_code' => null,
            'payment_method_id' => 'pm_card_visa',
            'shipping_address' => ['name' => 'Ada Lovelace', 'line1' => '1 Main St', 'line2' => null, 'city' => 'London', 'region' => null, 'postalCode' => 'N1 1AA', 'country' => 'GB'],
            'step_attempts' => 1,
            'next_attempt_at' => now()->addSeconds(30),
            'version' => 0,
        ];
    }
}
