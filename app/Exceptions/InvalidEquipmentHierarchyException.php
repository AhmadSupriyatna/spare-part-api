<?php

namespace App\Exceptions;

use Exception;

/**
 * Enforces the Phase 3B "Asset Outside Line" invariant: an Equipment must
 * have exactly one of machine_id (production) or branch_id (outside-line)
 * populated, never both, never neither.
 */
class InvalidEquipmentHierarchyException extends Exception
{
    //
}
