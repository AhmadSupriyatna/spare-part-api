<?php

namespace App\Exceptions;

use Exception;

class PartStockNotFoundException extends Exception
{
    public function __construct(int $partId, int $branchId)
    {
        parent::__construct("Part #{$partId} belum memiliki data stok di cabang #{$branchId}.");
    }
}
