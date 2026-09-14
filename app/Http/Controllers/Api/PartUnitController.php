<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartUnitResource;
use App\Models\Part;
use App\Models\PartUnit;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PartUnitController extends Controller
{
    /**
     * Every unit ever created for this part type — used to see what's
     * available to reinstall, in repair, in service, or scrapped.
     */
    public function index(Part $part): AnonymousResourceCollection
    {
        return PartUnitResource::collection(
            $part->units()->with('part')->orderBy('unit_code')->get()
        );
    }

    /**
     * One unit's full history — every installation (which equipment, how
     * long) and every repair cycle it's been through. The analytics view
     * the whole module exists to feed.
     */
    public function show(PartUnit $partUnit): PartUnitResource
    {
        return new PartUnitResource(
            $partUnit->load([
                'part',
                'installations.equipment.machine.line',
                'repairs',
            ])
        );
    }
}
