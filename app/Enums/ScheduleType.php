<?php

namespace App\Enums;

enum ScheduleType: string
{
    case Calendar = 'calendar';
    case Runtime = 'runtime';
    case Unscheduled = 'unscheduled';
}
