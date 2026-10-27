<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\TimeOutCheckoutSteps;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Re-send late saga commands, time out stuck steps into their compensation and retry closing carts.')]
#[Signature('checkout:time-out-steps')]
final class TimeOutCheckoutStepsCommand extends Command
{
    public function handle(TimeOutCheckoutSteps $timeOutSteps): int
    {
        $count = $timeOutSteps->handle();

        if ($count > 0) {
            $this->components->info("Handled {$count} due checkout(s).");
        }

        return self::SUCCESS;
    }
}
