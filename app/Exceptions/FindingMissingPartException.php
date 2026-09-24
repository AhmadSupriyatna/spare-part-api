<?php

namespace App\Exceptions;

use Exception;

class FindingMissingPartException extends Exception
{
    public function __construct(int $findingId)
    {
        parent::__construct(
            "Finding #{$findingId} tidak memiliki part — tidak dapat diputuskan sebagai Ganti Part (REPLACE_PART)."
        );
    }
}
