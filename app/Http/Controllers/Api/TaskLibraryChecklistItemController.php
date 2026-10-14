<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskLibraryChecklistItemRequest;
use App\Http\Resources\TaskLibraryChecklistItemResource;
use App\Models\TaskLibrary;
use App\Models\TaskLibraryChecklistItem;
use Illuminate\Http\Response;

class TaskLibraryChecklistItemController extends Controller
{
    public function store(StoreTaskLibraryChecklistItemRequest $request, TaskLibrary $taskLibrary): TaskLibraryChecklistItemResource
    {
        $data = $request->validated();
        $data['order'] ??= $taskLibrary->checklistItems()->count();

        $item = $taskLibrary->checklistItems()->create($data);

        return new TaskLibraryChecklistItemResource($item->load('parts.part'));
    }

    public function destroy(TaskLibraryChecklistItem $taskLibraryChecklistItem): Response
    {
        $taskLibraryChecklistItem->delete();

        return response()->noContent();
    }
}
