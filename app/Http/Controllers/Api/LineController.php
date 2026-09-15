<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddLineRuntimeRequest;
use App\Http\Requests\DestroyWithPasswordRequest;
use App\Http\Requests\StoreLineRequest;
use App\Http\Requests\UpdateLineRequest;
use App\Http\Resources\LineResource;
use App\Http\Resources\LineRuntimeLogResource;
use App\Models\Branch;
use App\Models\LineRuntimeLog;
use App\Models\ProductionLine;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class LineController extends Controller
{
    public function index(Branch $branch): AnonymousResourceCollection
    {
        return LineResource::collection($branch->lines()->orderBy('name')->get());
    }

    public function store(StoreLineRequest $request, Branch $branch): LineResource
    {
        $line = $branch->lines()->create($request->validated());

        return new LineResource($line);
    }

    public function show(ProductionLine $line): LineResource
    {
        return new LineResource($line);
    }

    public function update(UpdateLineRequest $request, ProductionLine $line): LineResource
    {
        $line->update($request->validated());

        return new LineResource($line);
    }

    public function destroy(DestroyWithPasswordRequest $request, ProductionLine $line): Response
    {
        $line->delete();

        return response()->noContent();
    }

    /**
     * Logs a new hour-meter reading: the technician reports the absolute
     * value they see on the meter, and this computes + stores the delta
     * against the last recorded reading rather than trusting a manually
     * calculated delta from the field.
     */
    public function addRuntime(AddLineRuntimeRequest $request, ProductionLine $line): LineResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $line, $data) {
            $locked = ProductionLine::whereKey($line->id)->lockForUpdate()->first();

            LineRuntimeLog::create([
                'line_id' => $locked->id,
                'previous_hours' => $locked->runtime_hours,
                'new_hours' => $data['current_reading'],
                'hours_added' => $data['current_reading'] - $locked->runtime_hours,
                'recorded_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $locked->update(['runtime_hours' => $data['current_reading']]);
        });

        return new LineResource($line->fresh());
    }

    public function runtimeLogs(ProductionLine $line): AnonymousResourceCollection
    {
        return LineRuntimeLogResource::collection(
            $line->runtimeLogs()->with('recordedBy')->paginate(10)
        );
    }
}
