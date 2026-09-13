<?php

namespace App\Enums;

enum StockAlertLevel: string
{
    case Low = 'low';
    case Critical = 'critical';
}
