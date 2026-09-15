<?php

namespace App\Enums;

enum PartUnitActionType: string
{
    case Remove = 'remove';
    case Reinstall = 'reinstall';
}
