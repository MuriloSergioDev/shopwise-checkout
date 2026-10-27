<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Models\Checkout;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Shopwise\Kit\Messaging\Envelope;

/**
 * Reads a saga reply's `data`. Replies are read field by field instead of through the generated DTOs because
 * those type nullable fields (paymentId, message, reservationId) as non-null strings.
 */
final readonly class Reply
{
    public function __construct(public Envelope $envelope) {}

    /**
     * The checkout the reply is about, read without a lock (transitions check the row's version instead).
     * Null (logged) when the checkout is unknown.
     */
    public function checkout(): ?Checkout
    {
        $checkout = Checkout::query()->find($this->string('checkoutId'));

        if (! $checkout instanceof Checkout) {
            Log::warning('Reply for an unknown checkout', ['type' => $this->envelope->type, 'id' => $this->envelope->id]);
        }

        return $checkout;
    }

    public function string(string $field): string
    {
        $value = $this->envelope->data[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("{$this->envelope->type}: field '{$field}' must be a non-empty string.");
        }

        return $value;
    }

    public function nullableString(string $field): ?string
    {
        $value = $this->envelope->data[$field] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<mixed>
     */
    public function list(string $field): array
    {
        $value = $this->envelope->data[$field] ?? [];

        return is_array($value) ? $value : [];
    }
}
