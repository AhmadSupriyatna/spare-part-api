<?php

namespace App\Exceptions;

use Exception;

class TaskLibraryEquipmentMismatchException extends Exception
{
    public function __construct(int $taskLibraryId, int $expectedEquipmentId)
    {
        parent::__construct(
            "Task Library #{$taskLibraryId} tidak sesuai dengan equipment #{$expectedEquipmentId} pada Finding ini."
        );
    }
}
