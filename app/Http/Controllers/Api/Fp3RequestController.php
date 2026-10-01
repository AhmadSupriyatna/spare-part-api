<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Fp3RequestAlreadyCompletedException;
use App\Exceptions\PartStockNotFoundException;
use App\Http\Controllers\Concerns\ClaimsWorkItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelFp3RequestRequest;
use App\Http\Requests\CompleteFp3RequestRequest;
use App\Http\Requests\ReceiveFp3RequestRequest;
use App\Http\Requests\ScheduleFp3RequestRequest;
use App\Http\Resources\Fp3RequestResource;
use App\Models\Branch;
use App\Models\Fp3Request;
use App\Services\Fp3PhotoService;
use App\Services\Fp3RequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class Fp3RequestController extends Controller
{
    use ClaimsWorkItem;

    public function __construct(
        private readonly Fp3RequestService $fp3Requests,
        private readonly Fp3PhotoService $photos,
    ) {}

    public function indexForBranch(Branch $branch): AnonymousResourceCollection
    {
        return Fp3RequestResource::collection(
            $branch->fp3Requests()->with(['receivedBy', 'partUsages.part'])->latest()->get()
        );
    }

    public function show(Fp3Request $fp3Request): Fp3RequestResource
    {
        return new Fp3RequestResource($fp3Request->load(['branch', 'receivedBy', 'partUsages.part']));
    }

    public function receive(ReceiveFp3RequestRequest $request, Fp3Request $fp3Request): Fp3RequestResource
    {
        $this->ensureCanWork($request, $fp3Request);

        $updated = $this->fp3Requests->receive($fp3Request, $request->validated()['due_date'] ?? null);

        return new Fp3RequestResource($updated);
    }

    /**
     * due_date only, no claim — the PM Calendar's "drag an unscheduled FP3
     * card onto a day" action. Deliberately doesn't go through
     * ensureCanWork(): scheduling is a planning action like
     * TaskLibraryController::schedule(), not claiming work.
     */
    public function schedule(ScheduleFp3RequestRequest $request, Fp3Request $fp3Request): Fp3RequestResource
    {
        $updated = $this->fp3Requests->schedule($fp3Request, $request->validated()['due_date']);

        return new Fp3RequestResource($updated);
    }

    public function complete(CompleteFp3RequestRequest $request, Fp3Request $fp3Request): JsonResponse|Fp3RequestResource
    {
        $this->ensureCanWork($request, $fp3Request);

        $data = $request->validated();

        if ($request->hasFile('photo')) {
            $data['completion_photo_path'] = $this->photos->store($request->file('photo'));
        }

        try {
            $updated = $this->fp3Requests->complete($fp3Request, $request->user(), $data);
        } catch (PartStockNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Fp3RequestAlreadyCompletedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return new Fp3RequestResource($updated->load(['partUsages.part']));
    }

    public function cancel(CancelFp3RequestRequest $request, Fp3Request $fp3Request): Fp3RequestResource
    {
        $this->ensureCanWork($request, $fp3Request);

        $updated = $this->fp3Requests->cancel($fp3Request, $request->validated()['notes'] ?? null);

        return new Fp3RequestResource($updated);
    }

    /**
     * Same claim-on-first-action rule as TaskController::ensureCanWork() —
     * received_by plays assigned_to's role here. See ClaimsWorkItem.
     */
    private function ensureCanWork(Request $request, Fp3Request $fp3Request): void
    {
        $this->ensureCanClaim($request, $fp3Request, 'received_by', 'FP3');
    }
}
