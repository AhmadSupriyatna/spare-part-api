<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\Branch;
use App\Support\BranchScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Date;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function __construct(private readonly BranchScopeResolver $scopeResolver) {}

    /**
     * "Log perubahan, setiap user di plant melakukan apa" — every logged
     * model's activity, filtered down to whichever ones actually belong to
     * this branch (there's no branch_id column on the log itself; it's
     * resolved per-row from the subject via the same BranchScopeResolver
     * that powers EnsureBranchAccess). Defaults to the current month, same
     * as the Stock Ledger report, to keep the fetch-then-filter bounded.
     */
    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $query = Activity::query()->with(['causer', 'subject'])->latest();

        if ($request->filled('from')) {
            $query->where('created_at', '>=', Date::parse($request->string('from'))->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Date::parse($request->string('to'))->endOfDay());
        }

        $activities = $query->limit(1000)->get()
            ->filter(fn (Activity $activity) => $this->resolveBranchId($activity) === $branch->id)
            ->values();

        return ActivityLogResource::collection($activities);
    }

    /**
     * Prefer resolving from the live subject, same as EnsureBranchAccess.
     * But once a subject is permanently deleted, that relation returns
     * null — there's no row left to walk relations from — which used to
     * make an entire deleted record's history (not just its delete event)
     * silently vanish from every branch-filtered view. Every model that
     * actually gets hard-deleted in this app (Supplier, Rack, Location,
     * Budget, PartUnitActionRequest, Branch) has branch_id as a plain
     * column, so it survives untouched in the attribute snapshot this
     * package already stores on the log row — read that instead once the
     * live lookup comes up empty. Models resolved via deep relations
     * (PartInstallation, PartReplacementRequest, PartRepair) don't have
     * branch_id in their own snapshot, but those are append-only workflow
     * records that are essentially never hard-deleted in practice, so this
     * doesn't try to cover that case.
     */
    private function resolveBranchId(Activity $activity): ?int
    {
        $branchId = $this->scopeResolver->resolve($activity->subject);

        if ($branchId !== null) {
            return $branchId;
        }

        $snapshot = (array) ($activity->attribute_changes['attributes'] ?? $activity->attribute_changes['old'] ?? []);

        return isset($snapshot['branch_id']) ? (int) $snapshot['branch_id'] : null;
    }
}
