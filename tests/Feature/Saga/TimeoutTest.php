<?php

declare(strict_types=1);

use App\Enums\CheckoutStatus;
use App\Enums\FailureReason;
use App\Enums\StepOutcome;
use Shopwise\Kit\Messaging\OutboxMessage;

// Deadlines are compared to the second; a running clock would add real test time to the travelled time.
beforeEach(fn () => $this->freezeTime());

it('re-sends a late command every 30 s, three times, then times the step out', function (): void {
    $checkout = startCheckout();

    passDeadline(29);
    expect(outbox('inventory.reserve-stock'))->toHaveCount(1);

    passDeadline(2);
    passDeadline(31);
    passDeadline(31);
    expect(outbox('inventory.reserve-stock'))->toHaveCount(4)
        ->and($checkout->refresh()->status)->toBe(CheckoutStatus::Reserving)
        ->and($checkout->step_attempts)->toBe(4);

    // The re-sent command carries the same data under a new envelope id.
    $first = outbox('inventory.reserve-stock')[0];
    $last = outbox('inventory.reserve-stock')[3];
    expect($last['lines'])->toBe($first['lines'])->and($last['checkoutId'])->toBe($first['checkoutId']);

    passDeadline(31);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and($checkout->failure_reason)->toBe(FailureReason::Timeout)
        ->and(outbox('inventory.reserve-stock'))->toHaveCount(4)
        ->and(outbox('inventory.release-reservation'))->toHaveCount(1)
        ->and($checkout->steps()->where('outcome', StepOutcome::TimedOut)->count())->toBe(1);

    reply($checkout, 'inventory.reservation-released.v1', ['reservationId' => null, 'lines' => []]);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Failed)
        ->and(outbox('checkout.failed')[0]['reason'])->toBe('timeout');
});

/**
 * Initial send plus three re-sends, then the deadline after the last re-send.
 */
function timeOutCurrentStep(): void
{
    foreach (range(1, 4) as $pass) {
        passDeadline(31);
    }
}

it('refunds first and only then releases when the payment step times out', function (): void {
    $checkout = checkoutIn(CheckoutStatus::Paying);

    timeOutCurrentStep();

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and(outbox('payments.request-payment'))->toHaveCount(4)
        ->and(outbox('payments.refund-payment'))->toHaveCount(1)
        ->and(outbox('payments.refund-payment')[0]['reason'])->toBe('timeout')
        ->and(outbox('inventory.release-reservation'))->toBe([]);

    reply($checkout, 'payments.payment-failed.v1', ['reason' => 'timeout']);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and(outbox('inventory.release-reservation'))->toHaveCount(1);

    reply($checkout, 'inventory.reservation-released.v1');

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Failed)
        ->and($checkout->failure_reason)->toBe(FailureReason::Timeout)
        ->and($checkout->payment_failure_reason)->toBeNull();
});

it('refunds and releases when the commit step times out', function (): void {
    $checkout = checkoutIn(CheckoutStatus::CommittingStock);

    timeOutCurrentStep();

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and(outbox('inventory.commit-reservation'))->toHaveCount(4)
        ->and(outbox('payments.refund-payment'))->toHaveCount(1)
        ->and(outbox('inventory.release-reservation'))->toHaveCount(1);
});

it('refunds and releases when the order step times out', function (): void {
    $checkout = checkoutIn(CheckoutStatus::PlacingOrder);

    timeOutCurrentStep();

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and($checkout->failure_reason)->toBe(FailureReason::Timeout)
        ->and(outbox('orders.place-order'))->toHaveCount(4)
        ->and(outbox('payments.refund-payment'))->toHaveCount(1)
        ->and(outbox('inventory.release-reservation'))->toHaveCount(1);

    reply($checkout, 'payments.refunded.v1', ['reason' => 'timeout']);
    reply($checkout, 'inventory.reservation-released.v1');

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Failed)
        ->and(outbox('checkout.failed')[0]['reason'])->toBe('timeout');
});

it('re-sends unconfirmed undo commands every 30 s three times, then every 5 minutes until they are confirmed', function (): void {
    $checkout = checkoutIn(CheckoutStatus::Paying);
    reply($checkout, 'payments.payment-failed.v1');
    expect(outbox('inventory.release-reservation'))->toHaveCount(1);

    passDeadline(31);
    passDeadline(31);
    passDeadline(31);
    expect(outbox('inventory.release-reservation'))->toHaveCount(4);

    passDeadline(31);
    passDeadline(240);
    expect(outbox('inventory.release-reservation'))->toHaveCount(4);

    passDeadline(31);
    expect(outbox('inventory.release-reservation'))->toHaveCount(5)
        ->and($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating);

    passDeadline(301);
    expect(outbox('inventory.release-reservation'))->toHaveCount(6);

    reply($checkout, 'inventory.reservation-released.v1');
    passDeadline(301);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Failed)
        ->and(outbox('inventory.release-reservation'))->toHaveCount(6);
});

it('leaves sagas that are not due alone', function (): void {
    $checkout = checkoutIn(CheckoutStatus::Paying);
    $version = $checkout->version;

    passDeadline(10);

    expect($checkout->refresh()->version)->toBe($version)
        ->and(outbox('payments.request-payment'))->toHaveCount(1);
});

it('continues the checkout trace when it re-sends a command from the scheduler', function (): void {
    fakeServices();
    $this->postJson('/api/checkouts', checkoutBody(), bearer() + ['Idempotency-Key' => 'attempt-0001', 'traceparent' => TRACEPARENT])->assertAccepted();

    passDeadline(31);

    $headers = OutboxMessage::query()->where('type', 'inventory.reserve-stock')->orderBy('id')->pluck('headers');
    expect($headers)->toHaveCount(2)
        ->and($headers[1]['traceparent'])->toContain(TRACE_ID);
});
