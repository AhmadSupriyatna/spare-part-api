<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskLibraryPartRequest;
use App\Http\Requests\UpdateTaskLibraryPartRequest;
use App\Http\Resources\TaskLibraryPartResource;
use App\Models\TaskLibrary;
use App\Models\TaskLibraryPart;
use Illuminate\Http\Response;

class TaskLibraryPartController extends Controller
{
    public function store(StoreTaskLibraryPartRequest $request, TaskLibrary $taskLibrary): TaskLibraryPartResource
    {
        $data = $request->validated();
        $data['quantity_required'] = $taskLibrary->equipment->activeInstallationCountForPart($data['part_id']);

        $part = $taskLibrary->parts()->create($data);

        return new TaskLibraryPartResource($part->load('part'));
    }

    public function update(UpdateTaskLibraryPartRequest $request, TaskLibraryPart $taskLibraryPart): TaskLibraryPartResource
    {
        $taskLibraryPart->update($request->validated());

        return new TaskLibraryPartResource($taskLibraryPart->load('part'));
    }

    public function destroy(TaskLibraryPart $taskLibraryPart): Response
    {
        $taskLibraryPart->delete();

        return response()->noContent();
    }
}
