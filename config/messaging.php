<?php

declare(strict_types=1);

use App\Messaging\Handlers\OrderCancelledHandler;
use App\Messaging\Handlers\OrderPlacedHandler;
use App\Messaging\Handlers\OrderRejectedHandler;
use App\Messaging\Handlers\PaymentFailedHandler;
use App\Messaging\Handlers\PaymentRequiresActionHandler;
use App\Messaging\Handlers\PaymentSucceededHandler;
use App\Messaging\Handlers\RefundedHandler;
use App\Messaging\Handlers\ReservationCommittedHandler;
use App\Messaging\Handlers\ReservationFailedHandler;
use App\Messaging\Handlers\ReservationReleasedHandler;
use App\Messaging\Handlers\StockReservedHandler;

return [
    'source' => env('SERVICE_NAME', 'checkout'),

    'consumer_group' => env('KAFKA_CONSUMER_GROUP_ID', env('SERVICE_NAME', 'checkout')),

    // Event type => handler. Every type listed here needs its topic in deploy/values.yaml → consumers.
    // All of them are replies to the saga's commands and drive CheckoutSaga (ADR-0004).
    'handlers' => [
        'inventory.stock-reserved' => StockReservedHandler::class,
        'inventory.reservation-failed' => ReservationFailedHandler::class,
        'inventory.reservation-committed' => ReservationCommittedHandler::class,
        'inventory.reservation-released' => ReservationReleasedHandler::class,
        'payments.payment-requires-action' => PaymentRequiresActionHandler::class,
        'payments.payment-succeeded' => PaymentSucceededHandler::class,
        'payments.payment-failed' => PaymentFailedHandler::class,
        'payments.refunded' => RefundedHandler::class,
        'orders.order-placed' => OrderPlacedHandler::class,
        'orders.order-rejected' => OrderRejectedHandler::class,
        'orders.order-cancelled' => OrderCancelledHandler::class,
    ],

    'max_attempts' => 3,

    'relay_batch_size' => 100,
];
