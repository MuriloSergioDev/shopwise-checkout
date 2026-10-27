<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Saga deadlines (ADR-0004): replies are 30 s late at the earliest, so a 10 s pass is precise enough.
Schedule::command('checkout:time-out-steps')->everyTenSeconds()->withoutOverlapping(60);
