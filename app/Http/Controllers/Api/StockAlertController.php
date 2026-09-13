<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockAlertResource;
use App\Models\Branch;
use App\Models\StockAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockAlertController extends Controller
{
    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $query = StockAlert::query()
            ->whereHas('partStock', fn ($q) => $q->where('branch_id', $branch->id))
            ->with(['partStock.part']);

        if (! $request->boolean('include_resolved')) {
            $query->where('is_resolved', false);
        }

        return StockAlertResource::collection(
            $query->orderByRaw("CASE level WHEN 'critical' THEN 0 WHEN 'low' THEN 1 ELSE 2 END")
                ->latest()
                ->get()
        );
    }
}
