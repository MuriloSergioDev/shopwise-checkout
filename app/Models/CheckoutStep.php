<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SagaStep;
use App\Enums\StepOutcome;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of a checkout's step log: a command sent or re-sent, a reply received or ignored, a timeout.
 *
 * @property int $id
 * @property string $checkout_id
 * @property SagaStep $step
 * @property StepOutcome $outcome
 * @property string|null $message_type
 * @property string|null $message_id
 * @property array<string, mixed>|null $detail
 * @property Carbon $created_at
 */
#[Unguarded]
final class CheckoutStep extends Model
{
    public const null UPDATED_AT = null;

    /**
     * @return BelongsTo<Checkout, $this>
     */
    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step' => SagaStep::class,
            'outcome' => StepOutcome::class,
            'detail' => 'array',
        ];
    }
}
