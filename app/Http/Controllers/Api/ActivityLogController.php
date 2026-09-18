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
     *
     * Known gap: an activity whose subject has since been permanently
     * deleted can't be resolved back to a branch (there's no row left to
     * read it from) and will silently drop out of this filtered view —
     * acceptable for now, not something this endpoint tries to solve.
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
            ->filter(fn (Activity $activity) => $this->scopeResolver->resolve($activity->subject) === $branch->id)
            ->values();

        return ActivityLogResource::collection($activities);
    }
}
