<?php

namespace App\Enums;

/**
 * Which of the two known record types a Finding was reported against — a
 * closed list, deliberately not an open polymorphic morph map. See
 * Finding::taskPartCheck()/partReplacementRequest().
 */
enum FindingSourceType: string
{
    case TaskPartCheck = 'task_part_check';
    case PartReplacementRequest = 'part_replacement_request';
}
