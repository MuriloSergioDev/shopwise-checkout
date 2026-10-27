<?php

declare(strict_types=1);

use App\Enums\CheckoutStatus;
use App\Enums\StepOutcome;
use App\Exceptions\StaleCheckout;
use App\Models\Checkout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Shopwise\Kit\Messaging\DispatchResult;
use Shopwise\Kit\Messaging\OutboxMessage;

it('drops a redelivered reply (same envelope id) without a second transition', function (): void {
    $checkout = startCheckout();
    $envelope = contractExample('inventory.stock-reserved.v1', ['checkoutId' => $checkout->id]);

    expect(deliver($envelope))->toBe(DispatchResult::Handled)
        ->and(deliver($envelope))->toBe(DispatchResult::Duplicate)
        ->and(outbox('payments.request-payment'))->toHaveCount(1);
});

it('ignores a re-published outcome (new envelope id, same data) once the saga moved on', function (string $type, CheckoutStatus $waitingIn): void {
    $checkout = checkoutIn($waitingIn);
    $commandsBefore = OutboxMessage::query()->count();
    $version = $checkout->version;

    reply($checkout, $type);

    expect($checkout->refresh()->version)->toBe($version)
        ->and(OutboxMessage::query()->count())->toBe($commandsBefore)
        ->and($checkout->steps()->get()->last()?->outcome)->toBe(StepOutcome::Ignored);
})->with([
    'stock-reserved while paying' => ['inventory.stock-reserved.v1', CheckoutStatus::Paying],
    'payment-succeeded while committing' => ['payments.payment-succeeded.v1', CheckoutStatus::CommittingStock],
    'reservation-committed while placing the order' => ['inventory.reservation-committed.v1', CheckoutStatus::PlacingOrder],
    'reservation-failed while paying' => ['inventory.reservation-failed.v1', CheckoutStatus::Paying],
    'order-rejected after completion' => ['orders.order-rejected.v1', CheckoutStatus::Completed],
    'order-placed after completion' => ['orders.order-placed.v1', CheckoutStatus::Completed],
    'refunded while nothing is being undone' => ['payments.refunded.v1', CheckoutStatus::PlacingOrder],
]);

it('closes the cart only once when order-placed is re-published', function (): void {
    $checkout = checkoutIn(CheckoutStatus::Completed);

    reply($checkout, 'orders.order-placed.v1');

    Http::assertSentCount(3);
    expect(outbox('checkout.completed'))->toHaveCount(1);
});

it('publishes checkout.failed once when undo confirmations are repeated', function (): void {
    $checkout = checkoutIn(CheckoutStatus::Paying);
    reply($checkout, 'payments.payment-failed.v1');

    reply($checkout, 'inventory.reservation-released.v1');
    reply($checkout, 'inventory.reservation-released.v1');
    reply($checkout, 'payments.payment-failed.v1');

    expect($checkout->refresh()->status)->toBe(CheckoutStatus::Failed)
        ->and(outbox('checkout.failed'))->toHaveCount(1);
});

it('ignores replies for unknown checkouts', function (): void {
    $envelope = contractExample('inventory.stock-reserved.v1');

    expect(deliver($envelope))->toBe(DispatchResult::Handled)
        ->and(Checkout::query()->count())->toBe(0);
});

it('refuses to save a checkout that changed since it was read', function (): void {
    $checkout = startCheckout();
    $stale = Checkout::query()->findOrFail($checkout->id);

    $checkout->step_attempts = 2;
    $checkout->saveVersioned();

    $stale->step_attempts = 3;
    expect(fn () => $stale->saveVersioned())->toThrow(StaleCheckout::class)
        ->and($checkout->refresh()->step_attempts)->toBe(2)
        ->and($checkout->version)->toBe($stale->version + 1);
});

it('replays a reply that lost an optimistic-lock race, so it is applied exactly once', function (): void {
    $checkout = startCheckout();
    $raced = false;
    // Another writer bumps the version between the handler reading the row and saving it. The bump runs in the
    // handler's transaction, so the failed attempt rolls it back too; the retry then reads a clean row.
    DB::listen(function ($query) use ($checkout, &$raced): void {
        if (! $raced && str_starts_with(mb_strtolower($query->sql), 'insert into "checkout_steps"')) {
            $raced = true;
            DB::table('checkouts')->where('id', $checkout->id)->increment('version');
        }
    });

    expect(deliver(contractExample('inventory.stock-reserved.v1', ['checkoutId' => $checkout->id])))->toBe(DispatchResult::Handled)
        ->and($raced)->toBeTrue()
        ->and($checkout->refresh()->status)->toBe(CheckoutStatus::Paying)
        ->and(outbox('payments.request-payment'))->toHaveCount(1);
});
