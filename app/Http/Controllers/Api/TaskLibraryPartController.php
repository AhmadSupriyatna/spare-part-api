<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskLibraryPartRequest;
use App\Http\Resources\TaskLibraryPartResource;
use App\Models\TaskLibrary;
use App\Models\TaskLibraryPart;
use Illuminate\Http\Response;

class TaskLibraryPartController extends Controller
{
    public function store(StoreTaskLibraryPartRequest $request, TaskLibrary $taskLibrary): TaskLibraryPartResource
    {
        $part = $taskLibrary->parts()->create($request->validated());

        return new TaskLibraryPartResource($part->load('part'));
    }

    public function destroy(TaskLibraryPart $taskLibraryPart): Response
    {
        $taskLibraryPart->delete();

        return response()->noContent();
    }
}
