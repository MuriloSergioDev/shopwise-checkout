<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CheckoutStatus;
use App\Enums\FailureReason;
use App\Exceptions\StaleCheckout;
use Database\Factories\CheckoutFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The checkout saga's state row (ADR-0004). Created by StartCheckout; every later change goes through
 * CheckoutSaga and saveVersioned(), so two writers can never both apply a transition.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $customer_email
 * @property string $idempotency_key
 * @property string $cart_id
 * @property int $cart_version
 * @property string|null $active_cart_id
 * @property CheckoutStatus $status
 * @property FailureReason|null $failure_reason
 * @property string|null $payment_failure_reason
 * @property string|null $payment_failure_message
 * @property list<array{sku: string, productName: string|null, variantName: string, quantity: int, unitAmount: int, lineAmount: int}> $lines
 * @property string $currency
 * @property int $subtotal
 * @property int $discount
 * @property int $total
 * @property string|null $coupon_code
 * @property string $payment_method_id
 * @property array{name: string, line1: string, line2: string|null, city: string, region: string|null, postalCode: string, country: string} $shipping_address
 * @property string|null $reservation_id
 * @property Carbon|null $reservation_expires_at
 * @property string|null $payment_id
 * @property string|null $order_id
 * @property int $step_attempts
 * @property Carbon|null $next_attempt_at
 * @property list<array{step: string, wave: int, done: bool, attempts: int, nextAttemptAt: string|null}>|null $compensations
 * @property array<string, string>|null $trace_context
 * @property Carbon|null $closing_started_at
 * @property Carbon|null $close_cart_alerted_at
 * @property int $version
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Unguarded]
final class Checkout extends Model
{
    /** @use HasFactory<CheckoutFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @return HasMany<CheckoutStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(CheckoutStep::class)->orderBy('id');
    }

    /**
     * Saves the changed attributes only if nobody else changed the row since it was read, and bumps `version`.
     *
     * @throws StaleCheckout when another writer got there first; the caller's transaction must roll back.
     */
    public function saveVersioned(): void
    {
        $readVersion = $this->version;
        $this->version = $readVersion + 1;
        $this->updated_at = Carbon::now();

        $updated = self::query()
            ->whereKey($this->id)
            ->where('version', $readVersion)
            ->toBase()
            ->update($this->getDirty());

        if ($updated === 0) {
            $this->version = $readVersion;

            throw new StaleCheckout("Checkout {$this->id} changed since version {$readVersion} was read.");
        }

        $this->syncOriginal();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CheckoutStatus::class,
            'failure_reason' => FailureReason::class,
            'cart_version' => 'integer',
            'lines' => 'array',
            'subtotal' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'shipping_address' => 'array',
            'reservation_expires_at' => 'datetime',
            'step_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'compensations' => 'array',
            'trace_context' => 'array',
            'closing_started_at' => 'datetime',
            'close_cart_alerted_at' => 'datetime',
            'version' => 'integer',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
