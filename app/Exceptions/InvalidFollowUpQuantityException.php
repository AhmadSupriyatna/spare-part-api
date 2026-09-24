<?php

namespace App\Exceptions;

use Exception;

class InvalidFollowUpQuantityException extends Exception
{
    public function __construct(int $quantity, int $activeCount)
    {
        parent::__construct(
            $quantity < 1
                ? 'Quantity penggantian harus minimal 1.'
                : "Equipment ini memiliki {$activeCount} unit part aktif, tetapi quantity yang diminta {$quantity}."
        );
    }
}
