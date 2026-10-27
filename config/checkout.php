<?php

declare(strict_types=1);

// Saga timing (ADR-0004 in shopwise-platform). Seconds unless the key says otherwise.
return [
    // A command is re-sent when its reply is this late...
    'reply_timeout_seconds' => (int) env('CHECKOUT_REPLY_TIMEOUT_SECONDS', 30),

    // ...at most this many times; after that the step has timed out.
    'max_resends' => (int) env('CHECKOUT_MAX_RESENDS', 3),

    // Compensation commands keep being re-sent at this interval once the resends above are used up.
    'compensation_retry_seconds' => (int) env('CHECKOUT_COMPENSATION_RETRY_SECONDS', 300),

    // While the customer does 3-D Secure the step waits until the action's expiresAt plus this, then re-sends.
    'payment_action_grace_seconds' => (int) env('CHECKOUT_PAYMENT_ACTION_GRACE_SECONDS', 60),

    // Closing the cart (and unlocking the cart of a failed checkout) is retried with exponential backoff from the first value up to the second.
    'close_cart_backoff_seconds' => (int) env('CHECKOUT_CLOSE_CART_BACKOFF_SECONDS', 5),
    'close_cart_max_backoff_seconds' => (int) env('CHECKOUT_CLOSE_CART_MAX_BACKOFF_SECONDS', 300),

    // A cart that still is not closed (or unlocked) after this long is logged as an error (once).
    'close_cart_alert_after_seconds' => (int) env('CHECKOUT_CLOSE_CART_ALERT_AFTER_SECONDS', 3600),

    // How many due checkouts one run of checkout:time-out-steps handles.
    'deadline_batch_size' => 100,
];
