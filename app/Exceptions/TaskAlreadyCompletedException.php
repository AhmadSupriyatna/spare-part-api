<?php

namespace App\Exceptions;

use Exception;

class TaskAlreadyCompletedException extends Exception
{
    public function __construct(int $taskId)
    {
        parent::__construct("Tugas #{$taskId} sudah selesai sebelumnya dan tidak dapat diproses ulang.");
    }
}
