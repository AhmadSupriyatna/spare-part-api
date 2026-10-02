<?php

namespace App\Exceptions;

use Exception;

/**
 * A submitted part_unit_id doesn't actually belong to the part_id it's being
 * installed against — the client (a QR scan, or any other source) can't be
 * trusted to say a unit belongs to the right part without this being
 * checked server-side first.
 */
class PartUnitPartMismatchException extends Exception
{
    public function __construct(int $partUnitId, int $expectedPartId)
    {
        parent::__construct("Unit part #{$partUnitId} bukan milik part #{$expectedPartId} — permintaan ditolak.");
    }
}
