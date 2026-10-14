<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssetCategoryRequest;
use App\Http\Requests\UpdateAssetCategoryRequest;
use App\Http\Resources\AssetCategoryResource;
use App\Models\AssetCategory;
use App\Models\Equipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AssetCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AssetCategoryResource::collection(
            AssetCategory::query()->where('type', 'equipment')->orderBy('name')->get()
        );
    }

    public function store(StoreAssetCategoryRequest $request): AssetCategoryResource
    {
        return new AssetCategoryResource(AssetCategory::create([
            ...$request->validated(),
            'type' => 'equipment',
        ]));
    }

    public function update(UpdateAssetCategoryRequest $request, AssetCategory $assetCategory): AssetCategoryResource
    {
        $name = $request->validated('name');
        $oldName = $assetCategory->name;

        abort_unless($assetCategory->type === 'equipment', 404);
        DB::transaction(function () use ($assetCategory, $name, $oldName) {
            Equipment::where('category', $oldName)->update(['category' => $name]);
            $assetCategory->update(['name' => $name]);
        });

        return new AssetCategoryResource($assetCategory->refresh());
    }

    public function destroy(AssetCategory $assetCategory): Response|JsonResponse
    {
        abort_unless($assetCategory->type === 'equipment', 404);
        $inUse = Equipment::where('category', $assetCategory->name)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'Kategori masih digunakan dan tidak dapat dihapus.',
            ], 409);
        }

        $assetCategory->delete();

        return response()->noContent();
    }
}
