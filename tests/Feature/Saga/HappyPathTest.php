<?php

declare(strict_types=1);

use App\Enums\CheckoutStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shopwise\Kit\Messaging\OutboxMessage;

it('runs reserve → pay → commit → place order → close cart and publishes checkout.completed', function (): void {
    $checkout = startCheckout();

    reply($checkout, 'inventory.stock-reserved.v1', ['reservationId' => 'RES-1']);
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Paying)
        ->and($checkout->reservation_id)->toBe('RES-1')
        ->and(outbox('payments.request-payment'))->toHaveCount(1)
        ->and(outbox('payments.request-payment')[0])->toMatchArray([
            'checkoutId' => $checkout->id,
            'customerId' => CUSTOMER_ID,
            'amount' => 7300,
            'currency' => 'USD',
            'paymentMethodId' => 'pm_card_visa',
        ]);

    reply($checkout, 'payments.payment-succeeded.v1', ['paymentId' => 'PAY-1']);
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::CommittingStock)
        ->and(outbox('inventory.commit-reservation'))->toHaveCount(1)
        ->and(outbox('inventory.commit-reservation')[0])->toMatchArray(['checkoutId' => $checkout->id, 'reservationId' => 'RES-1']);

    reply($checkout, 'inventory.reservation-committed.v1', ['reservationId' => 'RES-1']);
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::PlacingOrder)
        ->and(outbox('orders.place-order'))->toHaveCount(1)
        ->and(outbox('orders.place-order')[0])->toMatchArray([
            'checkoutId' => $checkout->id,
            'customerId' => CUSTOMER_ID,
            'email' => 'customer@shopwise.test',
            'lines' => $checkout->lines,
            'currency' => 'USD',
            'subtotal' => 7300,
            'discount' => 0,
            'total' => 7300,
            'couponCode' => null,
            'paymentId' => 'PAY-1',
            'shippingAddress' => ['name' => 'Ada Lovelace', 'line1' => '1 Main St', 'line2' => null, 'city' => 'London', 'region' => null, 'postalCode' => 'N1 1AA', 'country' => 'GB'],
        ]);

    reply($checkout, 'orders.order-placed.v1', ['orderId' => 'ORD-1']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://cart.test/internal/carts/'.CART_ID.'/checked-out'
        && $request->data() === [
            'checkoutId' => $checkout->id,
            'orderId' => 'ORD-1',
            'lines' => [['sku' => 'TEE-MER-BLA-M', 'quantity' => 2], ['sku' => 'MUG-CER-WHI', 'quantity' => 1]],
        ]
        && ! $request->hasHeader('Authorization'));

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Completed)
        ->and($checkout->order_id)->toBe('ORD-1')
        ->and($checkout->active_cart_id)->toBeNull()
        ->and($checkout->next_attempt_at)->toBeNull()
        ->and($checkout->completed_at)->not->toBeNull()
        ->and(outbox('checkout.completed'))->toHaveCount(1)
        ->and(outbox('checkout.completed')[0])->toMatchArray([
            'checkoutId' => $checkout->id,
            'customerId' => CUSTOMER_ID,
            'cartId' => CART_ID,
            'orderId' => 'ORD-1',
            'paymentId' => 'PAY-1',
            'currency' => 'USD',
            'subtotal' => 7300,
            'discount' => 0,
            'total' => 7300,
            'couponCode' => null,
        ])
        ->and(outbox('checkout.failed'))->toBe([])
        ->and($checkout->steps()->get()->map(fn ($s): string => "{$s->step->value}:{$s->outcome->value}")->all())->toBe([
            'start:replied',
            'reserve_stock:sent',
            'reserve_stock:replied',
            'request_payment:sent',
            'request_payment:replied',
            'commit_reservation:sent',
            'commit_reservation:replied',
            'place_order:sent',
            'place_order:replied',
            'close_cart:replied',
            'finish:sent',
        ]);

    $this->getJson("/api/checkouts/{$checkout->id}", bearer())
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.orderId', 'ORD-1');
});

it('sends every command keyed by the checkout id', function (): void {
    $checkout = checkoutIn(CheckoutStatus::Completed);

    $subjects = OutboxMessage::query()->pluck('subject')->unique()->values()->all();

    expect($subjects)->toBe([$checkout->id]);
});
