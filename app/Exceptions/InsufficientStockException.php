<?php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    public function __construct(int $available, int $requested)
    {
        parent::__construct("Stok tidak cukup: tersedia {$available}, diminta {$requested}.");
    }
}
