<?php

namespace App\Enums;

enum ReplacementRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
