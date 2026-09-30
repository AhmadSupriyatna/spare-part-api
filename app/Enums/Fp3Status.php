<?php

namespace App\Enums;

/** Same 4 states as TaskStatus, deliberately — the plain work lifecycle (claim/work/finish/cancel) is identical, FP3 just isn't a Task. */
enum Fp3Status: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
