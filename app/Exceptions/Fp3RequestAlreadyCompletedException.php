<?php

namespace App\Exceptions;

use Exception;

class Fp3RequestAlreadyCompletedException extends Exception
{
    public function __construct(int $fp3RequestId)
    {
        parent::__construct("FP3 #{$fp3RequestId} sudah selesai sebelumnya dan tidak dapat diproses ulang.");
    }
}
