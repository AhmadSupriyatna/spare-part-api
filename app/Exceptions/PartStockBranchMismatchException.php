<?php

namespace App\Exceptions;

use Exception;

/**
 * A task's part_stock_id belongs to a different branch than the task's own
 * equipment — never trusted from client input (or a stale branch switcher
 * state), always re-checked server-side before any stock movement.
 */
class PartStockBranchMismatchException extends Exception
{
    public function __construct(int $partStockId, int $expectedBranchId)
    {
        parent::__construct(
            "Stok #{$partStockId} berasal dari plant lain, bukan plant #{$expectedBranchId} tempat equipment tugas ini berada."
        );
    }
}
