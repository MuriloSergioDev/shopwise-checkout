<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happened to a step, one checkout_steps row each.
 */
enum StepOutcome: string
{
    case Sent = 'sent';
    case Resent = 'resent';
    case Replied = 'replied';
    case Ignored = 'ignored';
    case TimedOut = 'timed_out';
    case Failed = 'failed';
    case Retrying = 'retrying';
}
