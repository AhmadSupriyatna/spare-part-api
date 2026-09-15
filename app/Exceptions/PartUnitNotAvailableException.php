<?php

namespace App\Exceptions;

use Exception;

class PartUnitNotAvailableException extends Exception
{
    public function __construct(int $partUnitId)
    {
        parent::__construct("Unit part #{$partUnitId} belum siap dipasang (statusnya bukan 'Siap Dipasang').");
    }
}
