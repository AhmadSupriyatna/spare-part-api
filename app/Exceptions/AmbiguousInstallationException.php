<?php

namespace App\Exceptions;

use Exception;

class AmbiguousInstallationException extends Exception
{
    public function __construct(int $partId)
    {
        parent::__construct("Ada lebih dari satu unit part #{$partId} yang sedang terpasang di equipment ini — pilih unit mana yang dilepas.");
    }
}
