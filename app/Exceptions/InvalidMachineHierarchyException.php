<?php

namespace App\Exceptions;

use Exception;

/**
 * Enforces the "Mesin Luar Line" invariant: a Machine must have exactly one
 * of line_id (production) or branch_id (outside-line) populated, never
 * both, never neither. Mirrors InvalidEquipmentHierarchyException one level
 * up.
 */
class InvalidMachineHierarchyException extends Exception
{
    //
}
