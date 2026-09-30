<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The FP3 "Departemen" picklist — index() is bound under both the
 * authenticated group (Settings management) and the public
 * throttle:breakdown-public group (the no-login "Ajukan FP3" form needs the
 * same list), see routes/api.php.
 */
class DepartmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DepartmentResource::collection(Department::orderBy('name')->get());
    }

    public function store(StoreDepartmentRequest $request): DepartmentResource
    {
        return new DepartmentResource(Department::create($request->validated()));
    }

    public function update(UpdateDepartmentRequest $request, Department $department): DepartmentResource
    {
        $department->update($request->validated());

        return new DepartmentResource($department);
    }

    public function destroy(Department $department): Response
    {
        $department->delete();

        return response()->noContent();
    }
}
