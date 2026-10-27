<?php

declare(strict_types=1);

use App\Enums\CheckoutStatus;
use App\Enums\FailureReason;
use App\Models\Checkout;

function expectFailed(Checkout $checkout, FailureReason $reason): void
{
    $checkout->refresh();

    expect($checkout->status)->toBe(CheckoutStatus::Failed)
        ->and($checkout->failure_reason)->toBe($reason)
        ->and($checkout->active_cart_id)->toBeNull()
        ->and($checkout->next_attempt_at)->toBeNull()
        ->and($checkout->failed_at)->not->toBeNull()
        ->and(outbox('checkout.failed'))->toHaveCount(1)
        ->and(outbox('checkout.failed')[0])->toMatchArray([
            'checkoutId' => $checkout->id,
            'customerId' => CUSTOMER_ID,
            'cartId' => CART_ID,
            'reason' => $reason->value,
        ])
        ->and(outbox('checkout.completed'))->toBe([]);
}

it('fails as out_of_stock with nothing to undo when the reservation fails', function (): void {
    $checkout = startCheckout();

    reply($checkout, 'inventory.reservation-failed.v1');

    expectFailed($checkout, FailureReason::OutOfStock);
    expect(outbox('inventory.release-reservation'))->toBe([])
        ->and(outbox('payments.request-payment'))->toBe([]);
});

it('releases the reservation when the payment fails, then fails as payment_failed', function (): void {
    $checkout = checkoutIn(CheckoutStatus::Paying);

    reply($checkout, 'payments.payment-failed.v1', ['reason' => 'card_declined', 'message' => 'Your card was declined.']);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and($checkout->failure_reason)->toBe(FailureReason::PaymentFailed)
        ->and(outbox('inventory.release-reservation'))->toHaveCount(1)
        ->and(outbox('inventory.release-reservation')[0]['checkoutId'])->toBe($checkout->id)
        ->and(outbox('payments.refund-payment'))->toBe([])
        ->and(outbox('checkout.failed'))->toBe([]);

    $this->getJson("/api/checkouts/{$checkout->id}", bearer())
        ->assertJsonPath('data.status', 'compensating')
        ->assertJsonPath('data.failureReason', 'payment_failed')
        ->assertJsonPath('data.paymentFailure', ['reason' => 'card_declined', 'message' => 'Your card was declined.']);

    reply($checkout, 'inventory.reservation-released.v1', ['reason' => 'requested']);

    expectFailed($checkout, FailureReason::PaymentFailed);
    $this->getJson("/api/checkouts/{$checkout->id}", bearer())->assertJsonPath('data.paymentFailure.reason', 'card_declined');
});

it('refunds when the reservation expired before it was committed, then fails as reservation_expired', function (): void {
    $checkout = checkoutIn(CheckoutStatus::CommittingStock);

    reply($checkout, 'inventory.reservation-released.v1', ['reason' => 'expired']);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and(outbox('payments.refund-payment'))->toHaveCount(1)
        ->and(outbox('payments.refund-payment')[0])->toMatchArray(['checkoutId' => $checkout->id, 'reason' => 'reservation_expired'])
        ->and(outbox('inventory.release-reservation'))->toBe([])
        ->and(outbox('orders.place-order'))->toBe([]);

    reply($checkout, 'payments.refunded.v1', ['reason' => 'reservation_expired']);

    expectFailed($checkout, FailureReason::ReservationExpired);
});

it('only notes an expiry while still paying and lets the commit step decide', function (): void {
    $checkout = checkoutIn(CheckoutStatus::Paying);

    reply($checkout, 'inventory.reservation-released.v1', ['reason' => 'expired']);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Paying)
        ->and(outbox('payments.refund-payment'))->toBe([]);
});

it('refunds and releases in parallel when the order is rejected, and fails once both are confirmed', function (): void {
    $checkout = checkoutIn(CheckoutStatus::PlacingOrder);

    reply($checkout, 'orders.order-rejected.v1');

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and(outbox('payments.refund-payment'))->toHaveCount(1)
        ->and(outbox('payments.refund-payment')[0]['reason'])->toBe('order_rejected')
        ->and(outbox('inventory.release-reservation'))->toHaveCount(1);

    reply($checkout, 'inventory.reservation-released.v1', ['reason' => 'requested']);
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating);

    reply($checkout, 'payments.refunded.v1');

    expectFailed($checkout, FailureReason::OrderRejected);
});

it('treats payment-failed as the payment undone when there was nothing to refund', function (): void {
    $checkout = checkoutIn(CheckoutStatus::PlacingOrder);
    reply($checkout, 'orders.order-rejected.v1');

    reply($checkout, 'payments.payment-failed.v1', ['paymentId' => null, 'reason' => 'canceled', 'message' => null, 'stripePaymentIntentId' => null]);
    reply($checkout, 'inventory.reservation-released.v1', ['reservationId' => null, 'lines' => []]);

    expectFailed($checkout, FailureReason::OrderRejected);
});

it('releases again when stock is reserved after the checkout already failed', function (): void {
    $checkout = startCheckout();
    passDeadline(31);
    passDeadline(31);
    passDeadline(31);
    passDeadline(31);
    reply($checkout, 'inventory.reservation-released.v1', ['reservationId' => null, 'lines' => []]);
    expectFailed($checkout, FailureReason::Timeout);

    reply($checkout, 'inventory.stock-reserved.v1', ['reservationId' => 'RES-LATE']);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Failed)
        ->and(outbox('inventory.release-reservation'))->toHaveCount(2)
        ->and(outbox('payments.request-payment'))->toBe([])
        ->and(outbox('checkout.failed'))->toHaveCount(1);
});

it('refunds a payment that succeeds after the payment step timed out', function (): void {
    $checkout = checkoutIn(CheckoutStatus::Paying);
    foreach (range(1, 4) as $pass) {
        passDeadline(31);
    }
    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and(outbox('payments.refund-payment'))->toHaveCount(1);

    reply($checkout, 'payments.payment-succeeded.v1', ['paymentId' => 'PAY-LATE']);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and($checkout->payment_id)->toBe('PAY-LATE')
        ->and(outbox('payments.refund-payment'))->toHaveCount(2)
        ->and(outbox('inventory.commit-reservation'))->toBe([]);
});

it('keeps an order placed after its step timed out, and logs it, while the undo goes on', function (): void {
    $checkout = checkoutIn(CheckoutStatus::PlacingOrder);
    foreach (range(1, 4) as $pass) {
        passDeadline(31);
    }

    reply($checkout, 'orders.order-placed.v1', ['orderId' => 'ORD-LATE']);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and($checkout->order_id)->toBe('ORD-LATE')
        ->and(outbox('checkout.completed'))->toBe([]);
});
