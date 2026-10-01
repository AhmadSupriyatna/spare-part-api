<?php

namespace App\Enums;

/**
 * How a Part's effective minimum stock (per branch) is determined — see
 * PartStockController::attachComputedAttributes(). Unlike
 * PartReplacementStrategy, "Standard" is a safe default: most parts just
 * follow the plant's general policy, only ones where full spare coverage
 * per installed unit actually matters need "Installed" chosen explicitly.
 */
enum PartMinimumStockStrategy: string
{
    case Installed = 'installed';
    case Standard = 'standard';
}
