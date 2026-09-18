<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RackLevelResource;
use App\Models\Rack;
use App\Models\RackLevel;
use Illuminate\Http\Response;

class RackLevelController extends Controller
{
    /**
     * A level's number is always auto-assigned (see
     * RackLevel::nextLevelNumber()) — level 1 is the ground/bottom level.
     */
    public function store(Rack $rack): RackLevelResource
    {
        $level = $rack->levels()->create(['level_number' => RackLevel::nextLevelNumber($rack->id)]);

        return new RackLevelResource($level->load('rack', 'locations'));
    }

    public function destroy(RackLevel $rackLevel): Response
    {
        $rackLevel->delete();

        return response()->noContent();
    }
}
