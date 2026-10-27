<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per saga (ADR-0004). Updated with optimistic locking on `version`.
        Schema::create('checkouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('customer_id', 64);
            $table->string('customer_email');
            $table->string('idempotency_key', 64);
            $table->string('cart_id', 64);
            $table->unsignedInteger('cart_version');
            // = cart_id while the saga runs, null once it is final: one checkout in progress per cart.
            $table->string('active_cart_id', 64)->nullable()->unique();

            $table->string('status', 20);
            $table->string('failure_reason', 30)->nullable();
            $table->string('payment_failure_reason', 40)->nullable();
            $table->string('payment_failure_message', 500)->nullable();

            $table->json('lines');
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount');
            $table->unsignedBigInteger('total');
            $table->string('coupon_code', 32)->nullable();
            $table->string('payment_method_id');
            $table->json('shipping_address');

            $table->string('reservation_id', 26)->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->string('payment_id', 26)->nullable();
            $table->string('order_id', 26)->nullable();

            // Sends of the current forward step's command (1 = first send).
            $table->unsignedSmallInteger('step_attempts')->default(0);
            // When checkout:time-out-steps next looks at this saga; null when nothing is awaited.
            $table->timestamp('next_attempt_at')->nullable();
            // Undo steps once compensating: list of {step, wave, done, attempts, nextAttemptAt}.
            $table->json('compensations')->nullable();
            // W3C trace context of the checkout's span, so timed-out steps continue the same trace.
            $table->json('trace_context')->nullable();
            // Step 7 is retried until it works; an error is logged once it has failed for an hour.
            $table->timestamp('closing_started_at')->nullable();
            $table->timestamp('close_cart_alerted_at')->nullable();

            $table->unsignedInteger('version')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'idempotency_key']);
            $table->index(['status', 'created_at']);
            $table->index('next_attempt_at');
        });

        // Append-only log of everything the saga sent, received, ignored or gave up on.
        Schema::create('checkout_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('checkout_id')->constrained()->cascadeOnDelete();
            $table->string('step', 30);
            $table->string('outcome', 20);
            $table->string('message_type', 64)->nullable();
            $table->string('message_id', 26)->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('created_at');

            $table->index(['checkout_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_steps');
        Schema::dropIfExists('checkouts');
    }
};
