<?php

declare(strict_types=1);

use App\Http\Controllers\ListCheckoutsController;
use App\Http\Controllers\ShowCheckoutController;
use App\Http\Controllers\StartCheckoutController;
use Illuminate\Support\Facades\Route;

// Called only by the BFFs, always with the caller's token. Contract: shopwise-contracts/openapi/checkout.yaml.
Route::middleware('auth.jwt')->group(function (): void {
    Route::post('/checkouts', StartCheckoutController::class)->name('checkouts.store');
    Route::get('/checkouts/{checkoutId}', ShowCheckoutController::class)->name('checkouts.show');
});

Route::middleware('auth.jwt:admin')->group(function (): void {
    Route::get('/admin/checkouts', ListCheckoutsController::class)->name('admin.checkouts.index');
});
