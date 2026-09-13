<?php

namespace App\Enums;

enum ReorderStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Ordered = 'ordered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
