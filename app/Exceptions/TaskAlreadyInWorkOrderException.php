<?php

namespace App\Exceptions;

use Exception;

class TaskAlreadyInWorkOrderException extends Exception
{
    public function __construct(int $taskId)
    {
        parent::__construct("Tugas #{$taskId} sudah masuk ke WO lain.");
    }
}
