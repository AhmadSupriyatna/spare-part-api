<?php

namespace App\Exceptions;

use Exception;

class PartUnitNotInstalledException extends Exception
{
    public function __construct(int $partUnitId)
    {
        parent::__construct("Unit part #{$partUnitId} tidak sedang terpasang di mana pun — tidak bisa dilepas.");
    }
}
