<?php

namespace App\Http\Controllers\Api;

use App\Enums\Fp3Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicFp3RequestRequest;
use App\Http\Resources\Fp3RequestResource;
use App\Models\Branch;
use App\Models\Fp3Request;
use App\Services\Fp3PhotoService;

/**
 * Unauthenticated "Ajukan FP3" QR-scan submission — one generic QR printed
 * per branch (not tied to any machine/part/line, unlike every other QR flow
 * in this app), reached by any staff member in any department. No login,
 * same access level as PublicBreakdownController. Also serves the QR
 * Validator scanned off a printed FP3 (show()) — same controller since both
 * are "the public FP3 surface", just opposite ends of one request's life.
 */
class PublicFp3Controller extends Controller
{
    public function __construct(private readonly Fp3PhotoService $photos) {}

    /**
     * QR Validator's digital view — scanning the QR printed on a Cetak FP3
     * (built as `${origin}/fp3/scan/{id}`) lands here with no login, same
     * pattern as PublicMaintenanceReportController. Reuses Fp3RequestResource
     * as-is (no cost/price fields to hide), same precedent as every other
     * public QR controller in this app.
     */
    public function show(Fp3Request $fp3Request): Fp3RequestResource
    {
        return new Fp3RequestResource($fp3Request->load(['branch', 'receivedBy', 'partUsages.part']));
    }

    public function store(StorePublicFp3RequestRequest $request, Branch $branch): Fp3RequestResource
    {
        $data = $request->validated();
        $photoPath = $this->photos->store($request->file('photo'));

        $fp3Request = $branch->fp3Requests()->create([
            'code' => Fp3Request::generateCode($branch->id),
            'requester_name' => $data['requester_name'],
            'department' => $data['department'],
            'description' => $data['description'],
            'request_photo_path' => $photoPath,
            // Explicit rather than relying on the migration's DB-level
            // default — Eloquent's create() doesn't re-read a column back
            // from the DB after insert, so a value only set by a SQL
            // DEFAULT (never assigned in PHP) stays null on the returned
            // model instance until it's re-fetched.
            'status' => Fp3Status::Pending,
        ]);

        return new Fp3RequestResource($fp3Request);
    }
}
