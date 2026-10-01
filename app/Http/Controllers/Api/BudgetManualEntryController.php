<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBudgetManualEntryRequest;
use App\Http\Resources\BudgetManualEntryResource;
use App\Models\Budget;
use App\Models\BudgetManualEntry;
use Illuminate\Http\Response;

class BudgetManualEntryController extends Controller
{
    public function store(StoreBudgetManualEntryRequest $request, Budget $budget): BudgetManualEntryResource
    {
        $entry = $budget->manualEntries()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return new BudgetManualEntryResource($entry->load(['line', 'machine', 'creator']));
    }

    public function destroy(BudgetManualEntry $manualEntry): Response
    {
        $manualEntry->delete();

        return response()->noContent();
    }
}
