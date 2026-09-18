<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RackResource;
use App\Models\Branch;
use App\Models\Rack;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RackController extends Controller
{
    /**
     * The full visual map for a branch in one request — every rack, with
     * every level, with every bin (and how many parts sit in each), so the
     * Lokasi page can render the whole warehouse without a waterfall of
     * follow-up requests.
     */
    public function index(Branch $branch): AnonymousResourceCollection
    {
        return RackResource::collection(
            $branch->racks()
                ->with(['levels' => fn ($q) => $q->orderBy('level_number'), 'levels.locations' => fn ($q) => $q->withCount('partStocks')->orderBy('bin_number')])
                ->orderBy('label')
                ->get()
        );
    }

    /**
     * A rack's label is always auto-assigned (see Rack::nextLabel()) — the
     * whole point of "kode otomatis" is that nobody types a rack name.
     */
    public function store(Branch $branch): RackResource
    {
        $rack = $branch->racks()->create(['label' => Rack::nextLabel($branch->id)]);

        return new RackResource($rack->load('levels.locations'));
    }

    public function destroy(Rack $rack): Response
    {
        $rack->delete();

        return response()->noContent();
    }
}
