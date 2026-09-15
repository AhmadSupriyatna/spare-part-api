<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Supplier;
use App\Support\BranchScopeResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Superadmin and Supervisor see/manage every branch, as they do everywhere
 * else in the app. Every other role (Admin Spare Part, Engineer, Teknisi)
 * is confined to the branches actually assigned to their account — this is
 * the server-side enforcement of that; branches() on the user model was
 * previously only ever used to populate the branch switcher in the UI,
 * nothing stopped a request for a branch outside that list.
 *
 * Resolves the branch from whichever route-bound model the request touches
 * (see BranchScopeResolver) plus a couple of body fields that reference a
 * branch-scoped record without the route itself being scoped to it (e.g.
 * choosing a supplier_id/location_id when receiving stock).
 */
class EnsureBranchAccess
{
    public function __construct(private readonly BranchScopeResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->hasAnyRole([UserRole::Superadmin->value, UserRole::Supervisor->value])) {
            return $next($request);
        }

        $branchIds = $this->candidateBranchIds($request);

        $allowedBranchIds = $user->branches()->pluck('branches.id')->all();

        foreach ($branchIds as $branchId) {
            if (! in_array($branchId, $allowedBranchIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke cabang ini.');
            }
        }

        return $next($request);
    }

    /**
     * @return array<int, int>
     */
    private function candidateBranchIds(Request $request): array
    {
        $ids = [];

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if (is_object($parameter)) {
                $branchId = $this->resolver->resolve($parameter);
                if ($branchId !== null) {
                    $ids[] = $branchId;
                }
            }
        }

        if ($request->filled('supplier_id')) {
            $branchId = Supplier::find($request->input('supplier_id'))?->branch_id;
            if ($branchId !== null) {
                $ids[] = $branchId;
            }
        }

        if ($request->filled('location_id')) {
            $branchId = Location::find($request->input('location_id'))?->branch_id;
            if ($branchId !== null) {
                $ids[] = $branchId;
            }
        }

        return array_unique($ids);
    }
}
