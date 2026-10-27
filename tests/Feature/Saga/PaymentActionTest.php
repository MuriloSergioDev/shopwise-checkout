<?php

declare(strict_types=1);

use App\Enums\CheckoutStatus;
use App\Enums\FailureReason;
use App\Enums\SagaStep;
use App\Enums\StepOutcome;
use App\Models\Checkout;

// Deadlines are compared to the second; a running clock would add real test time to the travelled time.
beforeEach(fn () => $this->freezeTime());

const CLIENT_SECRET = 'pi_3QabcDEFghiJKL0a1b2c3d4e_secret_Z9y8X7w6V5u4T3s2R1q0';

/**
 * A checkout whose payment waits for 3-D Secure, the action expiring in ten minutes.
 */
function checkoutAwaitingAction(): Checkout
{
    $checkout = checkoutIn(CheckoutStatus::Paying);
    reply($checkout, 'payments.payment-requires-action.v1', ['expiresAt' => now()->addMinutes(10)->toIso8601ZuluString()]);

    return $checkout->refresh();
}

it('waits for the customer when the card needs 3-D Secure, and shows them the client secret', function (): void {
    $checkout = checkoutAwaitingAction();

    expect($checkout->status)->toBe(CheckoutStatus::AwaitingPaymentAction)
        ->and($checkout->payment_client_secret)->toBe(CLIENT_SECRET)
        ->and($checkout->payment_action_expires_at?->toIso8601ZuluString())->toBe(now()->addMinutes(10)->toIso8601ZuluString())
        ->and($checkout->next_attempt_at?->toIso8601ZuluString())->toBe(now()->addMinutes(11)->toIso8601ZuluString())
        ->and($checkout->payment_id)->toBe('01JBCDEFGHJKMNPQRSTVWXYZ43');

    $this->getJson("/api/checkouts/{$checkout->id}", bearer())
        ->assertJsonPath('data.status', 'awaiting_payment_action')
        ->assertJsonPath('data.clientSecret', CLIENT_SECRET)
        ->assertJsonPath('data.paymentActionExpiresAt', now()->addMinutes(10)->toIso8601ZuluString());

    $this->getJson('/api/admin/checkouts', bearer('admin-1', ['admin']))
        ->assertJsonPath('data.0.status', 'awaiting_payment_action')
        ->assertJsonPath('data.0.clientSecret', null)
        ->assertJsonPath('data.0.paymentActionExpiresAt', null);
});

it('goes on to commit the stock when the payment succeeds after the action, and forgets the secret', function (): void {
    $checkout = checkoutAwaitingAction();

    reply($checkout, 'payments.payment-succeeded.v1');

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::CommittingStock)
        ->and($checkout->payment_client_secret)->toBeNull()
        ->and($checkout->payment_action_expires_at)->toBeNull()
        ->and(outbox('inventory.commit-reservation'))->toHaveCount(1);

    $this->getJson("/api/checkouts/{$checkout->id}", bearer())
        ->assertJsonPath('data.clientSecret', null)
        ->assertJsonPath('data.paymentActionExpiresAt', null);
});

it('releases the reservation when the action fails, and fails as payment_failed', function (): void {
    $checkout = checkoutAwaitingAction();

    reply($checkout, 'payments.payment-failed.v1', ['reason' => 'authentication_required', 'message' => 'Authentication failed.']);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and($checkout->failure_reason)->toBe(FailureReason::PaymentFailed)
        ->and($checkout->payment_failure_reason)->toBe('authentication_required')
        ->and($checkout->payment_client_secret)->toBeNull()
        ->and(outbox('inventory.release-reservation'))->toHaveCount(1)
        ->and(outbox('payments.refund-payment'))->toBe([]);

    reply($checkout, 'inventory.reservation-released.v1', ['reason' => 'requested']);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Failed);
});

it('does not re-send request-payment while the action can still be done', function (): void {
    $checkout = checkoutAwaitingAction();

    passDeadline(31);
    passDeadline(560);

    expect(outbox('payments.request-payment'))->toHaveCount(1)
        ->and($checkout->refresh()->status)->toBe(CheckoutStatus::AwaitingPaymentAction);
});

it('re-sends request-payment after expiresAt plus a minute, then times out into refund and release', function (): void {
    $checkout = checkoutAwaitingAction();

    passDeadline(660);
    expect(outbox('payments.request-payment'))->toHaveCount(2);

    passDeadline(31);
    passDeadline(31);
    expect(outbox('payments.request-payment'))->toHaveCount(4)
        ->and($checkout->refresh()->status)->toBe(CheckoutStatus::AwaitingPaymentAction);

    passDeadline(31);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and($checkout->failure_reason)->toBe(FailureReason::Timeout)
        ->and($checkout->payment_client_secret)->toBeNull()
        ->and(outbox('payments.refund-payment'))->toHaveCount(1)
        ->and(outbox('inventory.release-reservation'))->toBe([]);

    reply($checkout, 'payments.payment-failed.v1', ['reason' => 'timeout']);
    reply($checkout, 'inventory.reservation-released.v1');

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Failed)
        ->and(outbox('checkout.failed')[0]['reason'])->toBe('timeout');
});

it('ignores a re-published requires-action while already waiting, and a redelivered one', function (): void {
    $checkout = checkoutAwaitingAction();
    $deadline = $checkout->next_attempt_at;

    $republished = reply($checkout, 'payments.payment-requires-action.v1', ['expiresAt' => now()->addMinutes(20)->toIso8601ZuluString()]);
    deliver($republished);

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::AwaitingPaymentAction)
        ->and($checkout->next_attempt_at?->toIso8601ZuluString())->toBe($deadline?->toIso8601ZuluString())
        ->and($checkout->steps()->where('step', SagaStep::RequestPayment)->where('outcome', StepOutcome::Ignored)->count())->toBe(1);
});

it('ignores requires-action in a later step', function (): void {
    $checkout = checkoutIn(CheckoutStatus::CommittingStock);

    reply($checkout, 'payments.payment-requires-action.v1');

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::CommittingStock)
        ->and($checkout->payment_client_secret)->toBeNull();
});

it('refunds (cancelling the intent) when requires-action arrives after the payment step gave up', function (): void {
    $checkout = checkoutIn(CheckoutStatus::Paying);
    foreach (range(1, 4) as $pass) {
        passDeadline(31);
    }
    expect(outbox('payments.refund-payment'))->toHaveCount(1);

    reply($checkout, 'payments.payment-requires-action.v1');

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Compensating)
        ->and($checkout->payment_client_secret)->toBeNull()
        ->and(outbox('payments.refund-payment'))->toHaveCount(2);
});
