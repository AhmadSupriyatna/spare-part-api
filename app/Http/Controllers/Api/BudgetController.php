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
        $query = $branch->budgets()->with(['creator', 'items.part', 'manualEntries.line', 'manualEntries.machine', 'manualEntries.creator'])
            ->orderByDesc('year')->orderBy('replacement_strategy');

        if ($request->filled('year')) {
            $query->where('year', $request->integer('year'));
        }

        $budgets = $query->get()->each(function (Budget $budget) use ($branch) {
            $budget->setAttribute(
                'realization',
                $this->realization->calculateRealization($branch, $budget->replacement_strategy, $budget->year, $budget)
            );
            $this->attachBreakdowns($budget, $branch);
        });

        return BudgetResource::collection($budgets);
    }

    /**
     * Parts under this replacement strategy (branch-wide) that currently
     * have an active installation, with current unit cost — what the budget
     * input drawer renders as the per-part input rows, before the user has
     * entered anything. Works for all 3 strategies now; which extra input
     * field the drawer asks for alongside this list depends on the
     * strategy (see StoreBudgetRequest).
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
            $budget = $branch->budgets()->create([
                'replacement_strategy' => $data['replacement_strategy'],
                'global_lifetime_percent' => $data['global_lifetime_percent'] ?? null,
                'global_price_increase_percent' => $data['global_price_increase_percent'] ?? 0,
                'year' => $data['year'],
                'planned_amount' => 0,
                'corrective_amount' => $data['corrective_amount'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $priced = $this->forecast->priceItems($branch, $budget, $data['items'] ?? []);
            $budget->items()->createMany($priced['items']);
            $budget->update(['planned_amount' => $priced['planned_amount']]);

            return $budget;
        });

        $budget->load(['creator', 'items.part', 'manualEntries.line', 'manualEntries.machine', 'manualEntries.creator']);
        $this->attachBreakdowns($budget, $branch);

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

            if (array_key_exists('global_lifetime_percent', $data)) {
                $updates['global_lifetime_percent'] = $data['global_lifetime_percent'];
            }
            if (array_key_exists('global_price_increase_percent', $data)) {
                $updates['global_price_increase_percent'] = $data['global_price_increase_percent'];
            }

            $budget->update($updates);

            if (isset($data['items'])) {
                $priced = $this->forecast->priceItems($budget->branch, $budget->fresh(), $data['items']);
                $budget->items()->delete();
                $budget->items()->createMany($priced['items']);
                $budget->update(['planned_amount' => $priced['planned_amount']]);
            }
        });

        $budget->load(['creator', 'items.part', 'manualEntries.line', 'manualEntries.machine', 'manualEntries.creator']);
        $this->attachBreakdowns($budget, $budget->branch);

        return new BudgetResource($budget);
    }

    public function destroy(Budget $budget): Response
    {
        $budget->delete();

        return response()->noContent();
    }

    private function attachBreakdowns(Budget $budget, Branch $branch): void
    {
        foreach ($budget->items as $item) {
            if (! $item->part) {
                continue;
            }

            $item->setAttribute('by_line', $this->forecast->lineBreakdown($branch, $item));
            $item->setAttribute('by_machine', $this->forecast->machineBreakdown($branch, $item));
        }
    }
}
