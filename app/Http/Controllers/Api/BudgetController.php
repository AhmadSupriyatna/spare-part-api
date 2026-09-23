<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartReplacementStrategy;
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
use Illuminate\Support\Facades\DB;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetForecastService $forecast,
        private readonly BudgetRealizationService $realization,
    ) {}

    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $query = $branch->budgets()->with(['creator', 'items.part'])->orderByDesc('year')->orderBy('replacement_strategy');

        if ($request->filled('year')) {
            $query->where('year', $request->integer('year'));
        }

        $budgets = $query->get()->each(function (Budget $budget) use ($branch) {
            $budget->setAttribute(
                'realization',
                $this->realization->calculateRealization($branch, $budget->replacement_strategy, $budget->year)
            );
            $this->attachLineBreakdowns($budget, $branch);
        });

        return BudgetResource::collection($budgets);
    }

    /**
     * Parts under this replacement strategy (branch-wide) that currently
     * have an active installation, with current unit cost — what the budget
     * input drawer renders as the per-part input rows, before the user has
     * entered anything. Only meaningful for LifeBased (the only strategy
     * with an automatic forecast); the drawer doesn't call this for any
     * other strategy.
     */
    public function partsForStrategy(Request $request, Branch $branch): JsonResponse
    {
        $strategy = $request->enum('strategy', PartReplacementStrategy::class);

        if (! $strategy) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $this->forecast->partsForStrategy($branch, $strategy)]);
    }

    public function store(StoreBudgetRequest $request, Branch $branch): BudgetResource
    {
        $data = $request->validated();

        $budget = DB::transaction(function () use ($data, $branch, $request) {
            $priced = $this->forecast->priceItems($branch, $data['items'] ?? []);

            $budget = $branch->budgets()->create([
                'replacement_strategy' => $data['replacement_strategy'],
                'year' => $data['year'],
                'planned_amount' => $priced['planned_amount'],
                'corrective_amount' => $data['corrective_amount'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $budget->items()->createMany($priced['items']);

            return $budget;
        });

        $budget->load(['creator', 'items.part']);
        $this->attachLineBreakdowns($budget, $branch);

        return new BudgetResource($budget);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): BudgetResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $budget) {
            $updates = [
                'corrective_amount' => $data['corrective_amount'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ];

            if (isset($data['items'])) {
                $priced = $this->forecast->priceItems($budget->branch, $data['items']);
                $updates['planned_amount'] = $priced['planned_amount'];
                $budget->items()->delete();
                $budget->items()->createMany($priced['items']);
            }

            $budget->update($updates);
        });

        $budget->load(['creator', 'items.part']);
        $this->attachLineBreakdowns($budget, $budget->branch);

        return new BudgetResource($budget);
    }

    public function destroy(Budget $budget): Response
    {
        $budget->delete();

        return response()->noContent();
    }

    private function attachLineBreakdowns(Budget $budget, Branch $branch): void
    {
        foreach ($budget->items as $item) {
            if (! $item->part) {
                continue;
            }

            $item->setAttribute('by_line', $this->forecast->lineBreakdown(
                $branch,
                $item->part,
                (float) $item->estimated_lifetime_percent,
                (float) $item->price_increase_percent,
            ));
        }
    }
}
