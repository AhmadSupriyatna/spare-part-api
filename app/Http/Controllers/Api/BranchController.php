<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyWithPasswordRequest;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BranchController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return BranchResource::collection(Branch::orderBy('name')->get());
    }

    public function store(StoreBranchRequest $request): BranchResource
    {
        $branch = Branch::create($request->validated());

        return new BranchResource($branch);
    }

    public function show(Branch $branch): BranchResource
    {
        return new BranchResource($branch);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        $branch->update($request->validated());

        return new BranchResource($branch);
    }

    /**
     * Soft, reversible: sets is_active=false so the branch stops showing up
     * for branch-scoped roles, without touching any of its data. This is
     * the action actually exposed in the UI — see the note on destroy()
     * below for why a hard delete isn't.
     */
    public function archive(DestroyWithPasswordRequest $request, Branch $branch): BranchResource
    {
        $branch->update(['is_active' => false]);

        return new BranchResource($branch);
    }

    /**
     * Restoring access can't lose anything, so this doesn't need the
     * password re-entry that archiving/deleting do.
     */
    public function unarchive(Branch $branch): BranchResource
    {
        $branch->update(['is_active' => true]);

        return new BranchResource($branch);
    }

    /**
     * Hard delete — cascades to every supplier, location/rack, part_stock,
     * line, and budget under this branch, permanently. Kept for
     * completeness (and gated the same as the other cascading deletes in
     * this app, see DestroyWithPasswordRequest), but nothing in the UI
     * currently calls this — archive() is what "Arsipkan Plant" actually
     * does, and should be reached for first in every real case.
     */
    public function destroy(DestroyWithPasswordRequest $request, Branch $branch): Response
    {
        $branch->delete();

        return response()->noContent();
    }
}
