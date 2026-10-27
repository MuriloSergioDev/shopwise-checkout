<?php

declare(strict_types=1);

namespace App\Saga;

use App\Enums\CheckoutStatus;
use App\Enums\FailureReason;
use App\Enums\SagaStep;
use App\Enums\StepOutcome;
use App\Models\Checkout;
use App\Models\CheckoutStep;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Shopwise\Kit\Messaging\Envelope;

/**
 * The checkout saga's state machine (ADR-0004). Every method is one transition: it must run inside a DB
 * transaction, checks the state the transition expects (anything else is a duplicate, stale or late reply and is
 * only logged), changes the row with an optimistic lock and records the next command in the outbox, so the state
 * and the message commit together.
 *
 * Forward: reserving → paying → committing_stock → placing_order → closing_cart → completed.
 * Any failure: compensating (undo commands in flight, in waves) → failed.
 */
final readonly class CheckoutSaga
{
    /**
     * Step 3: the row was just inserted (status reserving); send the first command.
     */
    public function begin(Checkout $checkout): void
    {
        $this->assertInTransaction();
        $this->log($checkout, SagaStep::Start, StepOutcome::Replied, null, [
            'cartVersion' => $checkout->cart_version,
            'total' => $checkout->total,
            'currency' => $checkout->currency,
        ]);
        $this->advance($checkout, CheckoutStatus::Reserving);
    }

    public function stockReserved(Checkout $checkout, Envelope $reply, string $reservationId, Carbon $expiresAt): void
    {
        $this->assertInTransaction();

        if ($checkout->status === CheckoutStatus::Reserving) {
            $this->log($checkout, SagaStep::ReserveStock, StepOutcome::Replied, $reply, ['reservationId' => $reservationId]);
            $checkout->reservation_id = $reservationId;
            $checkout->reservation_expires_at = $expiresAt;
            $this->advance($checkout, CheckoutStatus::Paying);

            return;
        }

        if ($checkout->status === CheckoutStatus::Compensating || $checkout->status === CheckoutStatus::Failed) {
            // A reservation made after we gave up (the reply was late, or the release overtook the reserve):
            // release it again so no stock stays held for a dead checkout.
            $checkout->reservation_id ??= $reservationId;
            $this->undoLateSuccess($checkout, SagaStep::ReleaseReservation, $reply);

            return;
        }

        $this->ignore($checkout, SagaStep::ReserveStock, $reply);
    }

    /**
     * @param  array<mixed>  $lines  the lines inventory could not reserve
     */
    public function reservationFailed(Checkout $checkout, Envelope $reply, string $reason, array $lines): void
    {
        $this->assertInTransaction();

        if ($checkout->status !== CheckoutStatus::Reserving) {
            $this->ignore($checkout, SagaStep::ReserveStock, $reply);

            return;
        }

        $this->log($checkout, SagaStep::ReserveStock, StepOutcome::Failed, $reply, ['reason' => $reason, 'lines' => $lines]);
        $this->compensate($checkout, FailureReason::OutOfStock, []);
    }

    public function paymentSucceeded(Checkout $checkout, Envelope $reply, string $paymentId): void
    {
        $this->assertInTransaction();

        if ($checkout->status === CheckoutStatus::Paying) {
            $this->log($checkout, SagaStep::RequestPayment, StepOutcome::Replied, $reply, ['paymentId' => $paymentId]);
            $checkout->payment_id = $paymentId;
            $this->advance($checkout, CheckoutStatus::CommittingStock);

            return;
        }

        if ($checkout->status === CheckoutStatus::Compensating || $checkout->status === CheckoutStatus::Failed) {
            // Money taken after we gave up: refund it (payments refunds at most once per checkout).
            $checkout->payment_id ??= $paymentId;
            $this->undoLateSuccess($checkout, SagaStep::RefundPayment, $reply);

            return;
        }

        $this->ignore($checkout, SagaStep::RequestPayment, $reply);
    }

    /**
     * The reply to request-payment (declined, canceled, timed out) or to refund-payment (nothing was taken).
     */
    public function paymentFailed(Checkout $checkout, Envelope $reply, string $reason, ?string $message): void
    {
        $this->assertInTransaction();

        if ($checkout->status === CheckoutStatus::Paying) {
            $this->log($checkout, SagaStep::RequestPayment, StepOutcome::Failed, $reply, ['reason' => $reason]);
            $checkout->payment_failure_reason = $reason;
            $checkout->payment_failure_message = $message;
            $this->compensate($checkout, FailureReason::PaymentFailed, [[SagaStep::ReleaseReservation]]);

            return;
        }

        $this->undoConfirmed($checkout, SagaStep::RefundPayment, $reply);
    }

    public function refunded(Checkout $checkout, Envelope $reply): void
    {
        $this->assertInTransaction();
        $this->undoConfirmed($checkout, SagaStep::RefundPayment, $reply);
    }

    public function reservationCommitted(Checkout $checkout, Envelope $reply): void
    {
        $this->assertInTransaction();

        if ($checkout->status !== CheckoutStatus::CommittingStock) {
            $this->ignore($checkout, SagaStep::CommitReservation, $reply);

            return;
        }

        $this->log($checkout, SagaStep::CommitReservation, StepOutcome::Replied, $reply);
        $this->advance($checkout, CheckoutStatus::PlacingOrder);
    }

    /**
     * Stock went back to available: the reply to release-reservation, the TTL passing, or the reply to
     * commit-reservation when the reservation had already expired.
     */
    public function reservationReleased(Checkout $checkout, Envelope $reply, string $reason): void
    {
        $this->assertInTransaction();

        if ($checkout->status === CheckoutStatus::CommittingStock && $reason === 'expired') {
            $this->log($checkout, SagaStep::CommitReservation, StepOutcome::Failed, $reply, ['reason' => $reason]);
            $this->compensate($checkout, FailureReason::ReservationExpired, [[SagaStep::RefundPayment]]);

            return;
        }

        // While paying, an expiry is only noted: commit-reservation will get the same answer and decide.
        $this->undoConfirmed($checkout, SagaStep::ReleaseReservation, $reply);
    }

    public function orderPlaced(Checkout $checkout, Envelope $reply, string $orderId): void
    {
        $this->assertInTransaction();

        if ($checkout->status === CheckoutStatus::PlacingOrder) {
            $this->log($checkout, SagaStep::PlaceOrder, StepOutcome::Replied, $reply, ['orderId' => $orderId]);
            $checkout->order_id = $orderId;
            $checkout->status = CheckoutStatus::ClosingCart;
            $checkout->step_attempts = 0;
            $checkout->closing_started_at = Carbon::now();
            // CloseCheckoutCart runs right after commit; this is the fallback for checkout:time-out-steps.
            $checkout->next_attempt_at = $this->closeCartRetryAt(1);
            $checkout->saveVersioned();

            return;
        }

        if ($checkout->status === CheckoutStatus::Compensating || $checkout->status === CheckoutStatus::Failed) {
            // Orders cannot cancel an order yet; the refund and release already in flight undo the money and stock.
            Log::error('Order placed for a checkout that is being undone', ['checkoutId' => $checkout->id, 'orderId' => $orderId]);
            $this->log($checkout, SagaStep::PlaceOrder, StepOutcome::Ignored, $reply, ['orderId' => $orderId, 'late' => true]);
            $checkout->order_id ??= $orderId;
            $checkout->saveVersioned();

            return;
        }

        $this->ignore($checkout, SagaStep::PlaceOrder, $reply);
    }

    public function orderRejected(Checkout $checkout, Envelope $reply, string $reason, ?string $message): void
    {
        $this->assertInTransaction();

        if ($checkout->status !== CheckoutStatus::PlacingOrder) {
            $this->ignore($checkout, SagaStep::PlaceOrder, $reply);

            return;
        }

        $this->log($checkout, SagaStep::PlaceOrder, StepOutcome::Failed, $reply, ['reason' => $reason, 'message' => $message]);
        $this->compensate($checkout, FailureReason::OrderRejected, [[SagaStep::RefundPayment, SagaStep::ReleaseReservation]]);
    }

    /**
     * Step 7 worked (or cart refused it for good, which no retry fixes): the checkout is done.
     */
    public function cartClosed(Checkout $checkout, bool $closedByCart): void
    {
        $this->assertInTransaction();

        if ($checkout->status !== CheckoutStatus::ClosingCart) {
            return;
        }

        if (! $closedByCart) {
            Log::error('Cart refused to close for a placed order; completing anyway', ['checkoutId' => $checkout->id, 'cartId' => $checkout->cart_id]);
        }

        $this->log($checkout, SagaStep::CloseCart, $closedByCart ? StepOutcome::Replied : StepOutcome::Failed);
        $checkout->status = CheckoutStatus::Completed;
        $checkout->completed_at = Carbon::now();
        $checkout->active_cart_id = null;
        $checkout->next_attempt_at = null;
        $checkout->saveVersioned();
        $this->log($checkout, SagaStep::Finish, StepOutcome::Sent, SagaMessages::completed($checkout));
    }

    /**
     * Step 7 failed in a way worth retrying: back off exponentially, and log an error once it has failed for an hour.
     */
    public function cartCloseFailed(Checkout $checkout, string $error): void
    {
        $this->assertInTransaction();

        if ($checkout->status !== CheckoutStatus::ClosingCart) {
            return;
        }

        $checkout->step_attempts++;
        $checkout->next_attempt_at = $this->closeCartRetryAt($checkout->step_attempts);
        $this->log($checkout, SagaStep::CloseCart, StepOutcome::Retrying, null, ['attempt' => $checkout->step_attempts, 'error' => $error]);

        $alertAfter = config()->integer('checkout.close_cart_alert_after_seconds');
        if ($checkout->close_cart_alerted_at === null
            && $checkout->closing_started_at?->lessThanOrEqualTo(Carbon::now()->subSeconds($alertAfter)) === true) {
            Log::error('Cart still not closed for a placed order', ['checkoutId' => $checkout->id, 'cartId' => $checkout->cart_id, 'attempts' => $checkout->step_attempts, 'error' => $error]);
            $checkout->close_cart_alerted_at = Carbon::now();
        }

        $checkout->saveVersioned();
    }

    /**
     * checkout:time-out-steps found next_attempt_at in the past: re-send the awaited command, time the step out
     * after the last re-send, or re-send the undo commands that are still unconfirmed.
     */
    public function deadlinePassed(Checkout $checkout): void
    {
        $this->assertInTransaction();
        $now = Carbon::now();

        if ($checkout->next_attempt_at === null || $checkout->next_attempt_at->greaterThan($now)) {
            return;
        }

        if ($checkout->status === CheckoutStatus::Compensating) {
            $this->resendDueCompensations($checkout);

            return;
        }

        $step = $checkout->status->awaitedStep();
        if (! $step instanceof SagaStep) {
            return;
        }

        if ($checkout->step_attempts <= config()->integer('checkout.max_resends')) {
            $checkout->step_attempts++;
            $checkout->next_attempt_at = $now->copy()->addSeconds(config()->integer('checkout.reply_timeout_seconds'));
            $checkout->saveVersioned();
            $this->log($checkout, $step, StepOutcome::Resent, SagaMessages::command($checkout, $step), ['attempt' => $checkout->step_attempts]);

            return;
        }

        $this->log($checkout, $step, StepOutcome::TimedOut, null, ['attempts' => $checkout->step_attempts]);
        $this->compensate($checkout, FailureReason::Timeout, match ($checkout->status) {
            // Release is a no-op when nothing was reserved.
            CheckoutStatus::Reserving => [[SagaStep::ReleaseReservation]],
            // Refund first (it also cancels a PaymentIntent that is not final), then release.
            CheckoutStatus::Paying => [[SagaStep::RefundPayment], [SagaStep::ReleaseReservation]],
            // Not in ADR-0004's table: the stock may or may not be committed, so undo both (release covers both).
            CheckoutStatus::CommittingStock, CheckoutStatus::PlacingOrder => [[SagaStep::RefundPayment, SagaStep::ReleaseReservation]],
            default => throw new LogicException("No timeout compensation for {$checkout->status->value}."),
        });
    }

    /**
     * Moves the saga to the next forward state and sends that state's command.
     */
    private function advance(Checkout $checkout, CheckoutStatus $status): void
    {
        $step = $status->awaitedStep() ?? throw new LogicException("{$status->value} awaits no command.");

        $checkout->status = $status;
        $checkout->step_attempts = 1;
        $checkout->next_attempt_at = Carbon::now()->addSeconds(config()->integer('checkout.reply_timeout_seconds'));
        $checkout->saveVersioned();
        $this->log($checkout, $step, StepOutcome::Sent, SagaMessages::command($checkout, $step));
    }

    /**
     * Starts undoing: waves of undo steps run one after another; the steps inside a wave run in parallel.
     * With nothing to undo the checkout fails straight away.
     *
     * @param  list<list<SagaStep>>  $waves
     */
    private function compensate(Checkout $checkout, FailureReason $reason, array $waves): void
    {
        $checkout->failure_reason = $reason;
        $checkout->step_attempts = 0;

        $compensations = [];
        foreach ($waves as $wave => $steps) {
            foreach ($steps as $step) {
                $compensations[] = ['step' => $step->value, 'wave' => $wave, 'done' => false, 'attempts' => 0, 'nextAttemptAt' => null];
            }
        }

        $checkout->compensations = $compensations;

        if ($compensations === []) {
            $this->fail($checkout);

            return;
        }

        $checkout->status = CheckoutStatus::Compensating;
        $this->continueCompensating($checkout);
    }

    /**
     * Sends the unsent steps of the first unfinished wave, or fails the checkout once every step is confirmed.
     */
    private function continueCompensating(Checkout $checkout): void
    {
        $compensations = $checkout->compensations ?? [];
        $pending = array_filter($compensations, fn (array $c): bool => ! $c['done']);

        if ($pending === []) {
            $this->fail($checkout);

            return;
        }

        $wave = min(array_column($pending, 'wave'));
        $sent = [];
        foreach ($compensations as $i => $compensation) {
            if (! $compensation['done'] && $compensation['wave'] === $wave && $compensation['attempts'] === 0) {
                $compensations[$i]['attempts'] = 1;
                $compensations[$i]['nextAttemptAt'] = $this->compensationRetryAt(1);
                $sent[] = SagaStep::from($compensation['step']);
            }
        }

        $checkout->compensations = $compensations;
        $checkout->next_attempt_at = $this->earliestCompensationAttempt($compensations);
        $checkout->saveVersioned();

        foreach ($sent as $step) {
            $this->log($checkout, $step, StepOutcome::Sent, SagaMessages::command($checkout, $step));
        }
    }

    private function resendDueCompensations(Checkout $checkout): void
    {
        $now = Carbon::now();
        $compensations = $checkout->compensations ?? [];
        $resent = [];

        foreach ($compensations as $i => $compensation) {
            if ($compensation['done'] || $compensation['attempts'] === 0 || $compensation['nextAttemptAt'] === null
                || Carbon::parse($compensation['nextAttemptAt'])->greaterThan($now)) {
                continue;
            }

            $attempts = $compensation['attempts'] + 1;
            $compensations[$i]['attempts'] = $attempts;
            $compensations[$i]['nextAttemptAt'] = $this->compensationRetryAt($attempts);
            $resent[] = [SagaStep::from($compensation['step']), $attempts];
        }

        $checkout->compensations = $compensations;
        $checkout->next_attempt_at = $this->earliestCompensationAttempt($compensations);
        $checkout->saveVersioned();

        foreach ($resent as [$step, $attempts]) {
            $this->log($checkout, $step, StepOutcome::Resent, SagaMessages::command($checkout, $step), ['attempt' => $attempts]);
        }
    }

    /**
     * An undo step's reply arrived: mark it done (whatever wave it is in: the effect is undone either way) and
     * move on. Outside compensating, or for a step that is not part of the plan, the reply is only logged.
     */
    private function undoConfirmed(Checkout $checkout, SagaStep $step, Envelope $reply): void
    {
        $compensations = $checkout->compensations ?? [];
        $index = null;
        foreach ($compensations as $i => $compensation) {
            if ($compensation['step'] === $step->value && ! $compensation['done']) {
                $index = $i;
            }
        }

        if ($checkout->status !== CheckoutStatus::Compensating || $index === null) {
            $this->ignore($checkout, $step, $reply);

            return;
        }

        $compensations[$index] = [...$compensations[$index], 'done' => true, 'nextAttemptAt' => null];
        $checkout->compensations = $compensations;
        $this->log($checkout, $step, StepOutcome::Replied, $reply);
        $this->continueCompensating($checkout);
    }

    /**
     * A forward step succeeded after the saga gave up on it. Sends its undo again (or for the first time); the
     * status does not change, and a failed checkout stays failed.
     */
    private function undoLateSuccess(Checkout $checkout, SagaStep $undo, Envelope $reply): void
    {
        $this->log($checkout, $undo === SagaStep::RefundPayment ? SagaStep::RequestPayment : SagaStep::ReserveStock, StepOutcome::Ignored, $reply, ['late' => true]);
        $checkout->saveVersioned();
        $this->log($checkout, $undo, StepOutcome::Resent, SagaMessages::command($checkout, $undo), ['reason' => 'late success']);
    }

    private function fail(Checkout $checkout): void
    {
        $checkout->status = CheckoutStatus::Failed;
        $checkout->failed_at = Carbon::now();
        $checkout->active_cart_id = null;
        $checkout->next_attempt_at = null;
        $checkout->saveVersioned();
        $this->log($checkout, SagaStep::Finish, StepOutcome::Sent, SagaMessages::failed($checkout), ['reason' => $checkout->failure_reason?->value]);
    }

    private function ignore(Checkout $checkout, SagaStep $step, Envelope $reply): void
    {
        $this->log($checkout, $step, StepOutcome::Ignored, $reply, ['status' => $checkout->status->value]);
    }

    /**
     * @param  array<string, mixed>|null  $detail
     */
    private function log(Checkout $checkout, SagaStep $step, StepOutcome $outcome, ?Envelope $message = null, ?array $detail = null): void
    {
        CheckoutStep::query()->create([
            'checkout_id' => $checkout->id,
            'step' => $step,
            'outcome' => $outcome,
            'message_type' => $message?->type,
            'message_id' => $message?->id,
            'detail' => $detail,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * 30 s for the first sends (initial + max_resends), then every compensation_retry_seconds until replied.
     */
    private function compensationRetryAt(int $attempts): string
    {
        $delay = $attempts <= config()->integer('checkout.max_resends')
            ? config()->integer('checkout.reply_timeout_seconds')
            : config()->integer('checkout.compensation_retry_seconds');

        return Carbon::now('UTC')->addSeconds($delay)->toIso8601ZuluString('millisecond');
    }

    /**
     * @param  list<array{step: string, wave: int, done: bool, attempts: int, nextAttemptAt: string|null}>  $compensations
     */
    private function earliestCompensationAttempt(array $compensations): ?Carbon
    {
        $due = array_filter(array_column($compensations, 'nextAttemptAt'));

        return $due === [] ? null : Carbon::parse(min($due));
    }

    private function closeCartRetryAt(int $attempts): Carbon
    {
        $delay = min(
            config()->integer('checkout.close_cart_backoff_seconds') * 2 ** max(0, $attempts - 1),
            config()->integer('checkout.close_cart_max_backoff_seconds'),
        );

        return Carbon::now()->addSeconds($delay);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Saga transitions must run inside DB::transaction.');
        }
    }
}
