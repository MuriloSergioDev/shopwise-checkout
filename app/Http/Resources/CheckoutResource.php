<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\CheckoutStatus;
use App\Enums\FailureReason;
use App\Models\Checkout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape defined in shopwise-contracts/openapi/checkout.yaml (components.schemas.Checkout).
 *
 * @mixin Checkout
 */
final class CheckoutResource extends JsonResource
{
    private bool $forCustomer = false;

    /**
     * Shows the 3-D Secure client secret, which only the checkout's own customer may see (never admin listings).
     */
    public function forCustomer(): self
    {
        $this->forCustomer = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'cartId' => $this->cart_id,
            'customerId' => $this->customer_id,
            'orderId' => $this->order_id,
            'failureReason' => $this->failure_reason?->value,
            'paymentFailure' => $this->failure_reason === FailureReason::PaymentFailed && $this->payment_failure_reason !== null
                ? ['reason' => $this->payment_failure_reason, 'message' => $this->payment_failure_message]
                : null,
            'clientSecret' => $this->showsPaymentAction() ? $this->payment_client_secret : null,
            'paymentActionExpiresAt' => $this->showsPaymentAction() ? $this->payment_action_expires_at?->toIso8601ZuluString() : null,
            'lines' => $this->lines,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'total' => $this->total,
            'couponCode' => $this->coupon_code,
            'createdAt' => $this->created_at->toIso8601ZuluString(),
            'updatedAt' => $this->updated_at->toIso8601ZuluString(),
        ];
    }

    private function showsPaymentAction(): bool
    {
        return $this->forCustomer && $this->status === CheckoutStatus::AwaitingPaymentAction;
    }
}
