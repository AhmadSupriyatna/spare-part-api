<?php

namespace App\Enums;

/**
 * The outcome of one maintenance observation/inspection — deliberately the
 * single field that answers "what should happen next, roughly," so it is
 * never duplicated by a separate "condition" field (see Finding).
 */
enum FindingResult: string
{
    case Pass = 'pass';
    case Monitor = 'monitor';
    case ActionRequired = 'action_required';
    case Fail = 'fail';
}
