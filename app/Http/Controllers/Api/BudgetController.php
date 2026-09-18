<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Branch;
use App\Models\Budget;
use App\Services\BudgetForecastService;
use App\Services\BudgetRealizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetForecastService $forecast,
        private readonly BudgetRealizationService $realization,
    ) {}

    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $query = $branch->budgets()->with('creator')->orderByDesc('year')->orderBy('category');

        if ($request->filled('year')) {
            $query->where('year', $request->integer('year'));
        }

        $budgets = $query->get()->each(function (Budget $budget) use ($branch) {
            $budget->setAttribute('realization', $this->realization->calculateRealization($branch, $budget->category, $budget->year));
        });

        return BudgetResource::collection($budgets);
    }

    /**
     * The lifetime-based suggestion behind the "Hitung Estimasi" button —
     * doesn't persist anything, just returns a number (plus the per-part
     * breakdown and which parts couldn't be forecast) for the form to
     * pre-fill. The user still saves the actual budget via store().
     */
    public function estimate(Request $request, Branch $branch): JsonResponse
    {
        $category = (string) $request->string('category');

        return response()->json(
            $this->forecast->estimateCategoryBudget($branch, $category)
        );
    }

    public function store(StoreBudgetRequest $request, Branch $branch): BudgetResource
    {
        $budget = $branch->budgets()->create([
            ...$request->validated(),
            'category' => $request->validated('category') ?: '',
            'corrective_amount' => $request->validated('corrective_amount') ?: 0,
            'created_by' => $request->user()->id,
        ]);

        return new BudgetResource($budget->load('creator'));
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): BudgetResource
    {
        $budget->update([
            ...$request->validated(),
            'corrective_amount' => $request->validated('corrective_amount') ?: 0,
        ]);

        return new BudgetResource($budget->load('creator'));
    }

    public function destroy(Budget $budget): Response
    {
        $budget->delete();

        return response()->noContent();
    }
}
