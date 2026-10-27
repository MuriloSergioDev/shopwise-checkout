<?php

declare(strict_types=1);

use App\Enums\CheckoutStatus;
use App\Models\Checkout;

it('shows the caller their checkout', function (): void {
    $checkout = Checkout::factory()->create(['customer_id' => CUSTOMER_ID]);

    $this->getJson("/api/checkouts/{$checkout->id}", bearer())
        ->assertOk()
        ->assertJsonPath('data.id', $checkout->id)
        ->assertJsonPath('data.status', 'reserving')
        ->assertJsonPath('data.couponCode', null)
        ->assertJsonStructure(['data' => ['id', 'status', 'cartId', 'customerId', 'orderId', 'failureReason', 'paymentFailure', 'lines', 'currency', 'subtotal', 'discount', 'total', 'couponCode', 'createdAt', 'updatedAt']]);
});

it('hides other customers checkouts and unknown ids behind 404', function (): void {
    $checkout = Checkout::factory()->create(['customer_id' => 'someone-else']);

    $this->getJson("/api/checkouts/{$checkout->id}", bearer())->assertNotFound();
    $this->getJson('/api/checkouts/unknown', bearer())->assertNotFound();
});

it('needs a token to show a checkout', function (): void {
    $this->getJson('/api/checkouts/anything')->assertUnauthorized();
});

it('lists checkouts for admins, newest first, filtered by status', function (): void {
    Checkout::factory()->create(['created_at' => now()->subMinutes(2)]);
    $failed = Checkout::factory()->create(['status' => CheckoutStatus::Failed, 'active_cart_id' => null, 'created_at' => now()->subMinute()]);
    $newest = Checkout::factory()->create();

    $this->getJson('/api/admin/checkouts?perPage=2', bearer(roles: ['admin']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $newest->id)
        ->assertJsonPath('data.1.id', $failed->id)
        ->assertJsonPath('meta', ['page' => 1, 'perPage' => 2, 'total' => 3, 'lastPage' => 2]);

    $this->getJson('/api/admin/checkouts?status=failed', bearer(roles: ['admin']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $failed->id);
});

it('keeps the admin list for admins', function (): void {
    $this->getJson('/api/admin/checkouts', bearer())->assertForbidden();
    $this->getJson('/api/admin/checkouts?status=nope', bearer(roles: ['admin']))->assertUnprocessable();
});
