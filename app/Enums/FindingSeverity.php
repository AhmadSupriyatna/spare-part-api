<?php

namespace App\Enums;

/**
 * How urgent a Finding is — orthogonal to `result` (a FAIL can be low or
 * critical severity; only meaningful once result != pass, enforced at the
 * service/request layer, not here).
 */
enum FindingSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
