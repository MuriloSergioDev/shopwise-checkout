<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ADR-0004 amendment: 3-D Secure (awaiting_payment_action) and the cart lock.
        Schema::table('checkouts', function (Blueprint $table): void {
            $table->string('status', 30)->change();
            // Set only while awaiting_payment_action; shown to the customer for stripe.handleNextAction.
            $table->string('payment_client_secret')->nullable();
            $table->timestamp('payment_action_expires_at')->nullable();
            // A failed checkout unlocks its cart over REST (retried like step 7); set once cart answered.
            $table->timestamp('cart_unlocked_at')->nullable();
            $table->timestamp('cart_unlock_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table): void {
            $table->dropColumn(['payment_client_secret', 'payment_action_expires_at', 'cart_unlocked_at', 'cart_unlock_alerted_at']);
            $table->string('status', 20)->change();
        });
    }
};
