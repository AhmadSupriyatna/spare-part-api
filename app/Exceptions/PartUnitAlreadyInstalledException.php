<?php

namespace App\Exceptions;

use Exception;

class PartUnitAlreadyInstalledException extends Exception
{
    public function __construct(int $partUnitId)
    {
        parent::__construct("Unit part #{$partUnitId} sedang terpasang di tempat lain — lepas dulu sebelum dipasang ulang.");
    }
}
