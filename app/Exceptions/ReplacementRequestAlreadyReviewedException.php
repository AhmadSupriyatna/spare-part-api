<?php

namespace App\Exceptions;

use App\Enums\ReplacementRequestStatus;
use Exception;

class ReplacementRequestAlreadyReviewedException extends Exception
{
    public function __construct(int $requestId, ReplacementRequestStatus $currentStatus)
    {
        parent::__construct(
            "Permintaan #{$requestId} sudah diputuskan sebelumnya (status: {$currentStatus->value})."
        );
    }
}
