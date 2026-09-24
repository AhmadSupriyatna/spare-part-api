<?php

namespace App\Exceptions;

use Exception;

class FindingAlreadyDecidedException extends Exception
{
    public function __construct(int $findingId)
    {
        parent::__construct(
            "Finding #{$findingId} sudah memiliki keputusan (Maintenance Decision) — tidak dapat diputuskan dua kali."
        );
    }
}
