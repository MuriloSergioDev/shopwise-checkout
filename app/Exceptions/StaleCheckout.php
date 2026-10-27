<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Optimistic lock conflict on a checkout row. Thrown inside a transaction so it rolls back; the kit's dispatcher
 * retries the message, and checkout:time-out-steps picks the saga up again on its next run.
 */
final class StaleCheckout extends RuntimeException {}
