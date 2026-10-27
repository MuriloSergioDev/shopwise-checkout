<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\StartCheckoutData;
use Illuminate\Foundation\Http\FormRequest;
use Shopwise\Kit\Auth\JwtUser;

final class StartCheckoutRequest extends FormRequest
{
    /**
     * The Idempotency-Key header is validated with the body, as `idempotencyKey`.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        /** @var array<string, mixed> $body */
        $body = $this->all();

        return [...$body, 'idempotencyKey' => $this->header('Idempotency-Key')];
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'idempotencyKey' => ['required', 'string', 'min:8', 'max:64'],
            'cartId' => ['required', 'string', 'max:64'],
            'paymentMethodId' => ['required', 'string', 'max:255', 'starts_with:pm_'],
            'shippingAddress' => ['required', 'array'],
            'shippingAddress.name' => ['required', 'string', 'max:100'],
            'shippingAddress.line1' => ['required', 'string', 'max:200'],
            'shippingAddress.line2' => ['nullable', 'string', 'max:200'],
            'shippingAddress.city' => ['required', 'string', 'max:100'],
            'shippingAddress.region' => ['nullable', 'string', 'max:100'],
            'shippingAddress.postalCode' => ['required', 'string', 'max:20'],
            'shippingAddress.country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['idempotencyKey.required' => 'The Idempotency-Key header is required.'];
    }

    public function toData(): StartCheckoutData
    {
        /** @var JwtUser $user */
        $user = $this->user();

        return new StartCheckoutData(
            customerId: $user->id,
            customerEmail: (string) $user->email,
            bearerToken: (string) $this->bearerToken(),
            idempotencyKey: (string) $this->header('Idempotency-Key'),
            cartId: $this->string('cartId')->toString(),
            paymentMethodId: $this->string('paymentMethodId')->toString(),
            shippingAddress: [
                'name' => $this->string('shippingAddress.name')->toString(),
                'line1' => $this->string('shippingAddress.line1')->toString(),
                'line2' => $this->filled('shippingAddress.line2') ? $this->string('shippingAddress.line2')->toString() : null,
                'city' => $this->string('shippingAddress.city')->toString(),
                'region' => $this->filled('shippingAddress.region') ? $this->string('shippingAddress.region')->toString() : null,
                'postalCode' => $this->string('shippingAddress.postalCode')->toString(),
                'country' => $this->string('shippingAddress.country')->toString(),
            ],
            traceHeaders: array_filter([
                'traceparent' => (string) $this->header('traceparent'),
                'tracestate' => (string) $this->header('tracestate'),
            ]),
        );
    }
}
