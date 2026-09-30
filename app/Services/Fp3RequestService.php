<?php

namespace App\Services;

use App\Enums\Fp3Status;
use App\Exceptions\Fp3RequestAlreadyCompletedException;
use App\Exceptions\PartStockNotFoundException;
use App\Models\Fp3PartUsage;
use App\Models\Fp3Request;
use App\Models\PartStock;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Fp3RequestService
{
    public function __construct(private readonly StockMovementService $stockMovements) {}

    public function receive(Fp3Request $fp3Request, ?string $dueDate = null): Fp3Request
    {
        $fp3Request->update([
            'status' => Fp3Status::InProgress,
            'received_at' => now(),
            'due_date' => $dueDate,
        ]);

        return $fp3Request;
    }

    /**
     * The Engineer's report — work_description/executor_names/
     * area_condition_after/disposition are always required (see
     * CompleteFp3RequestRequest); each part used is recorded as its own
     * Fp3PartUsage row and immediately issued out of stock through the same
     * StockMovementService path every other stock-out uses — no separate
     * approval step, since (unlike Breakdown) the person entering it here is
     * the Engineer completing the job, not the original anonymous requester.
     *
     * @param  array{work_description: string, executor_names: string, area_condition_after: string, disposition: string, disposition_note?: ?string, completion_photo_path?: ?string, parts?: list<array{part_id?: ?int, part_name_manual?: ?string, quantity: int, notes?: ?string}>}  $data
     *
     * @throws Fp3RequestAlreadyCompletedException if already Completed — checked
     *                                             first, before any stock mutation, so a duplicate submit can't issue stock twice.
     * @throws PartStockNotFoundException if a used part has no stock record in this request's branch.
     */
    public function complete(Fp3Request $fp3Request, ?User $user, array $data): Fp3Request
    {
        return DB::transaction(function () use ($fp3Request, $user, $data) {
            /** @var Fp3Request $locked */
            $locked = Fp3Request::whereKey($fp3Request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Fp3Status::Completed) {
                throw new Fp3RequestAlreadyCompletedException($locked->id);
            }

            $locked->update([
                'status' => Fp3Status::Completed,
                // Backfilled here if the Engineer went straight to "Selesai"
                // without a separate "Terima" click first ("bisa juga
                // langsung dikerjakan") — every completed request still ends
                // up with a real received_at, just equal to completed_at.
                'received_at' => $locked->received_at ?? now(),
                'completed_at' => now(),
                'work_description' => $data['work_description'],
                'executor_names' => $data['executor_names'],
                'area_condition_after' => $data['area_condition_after'],
                'disposition' => $data['disposition'],
                'disposition_note' => $data['disposition_note'] ?? null,
                'completion_photo_path' => $data['completion_photo_path'] ?? $locked->completion_photo_path,
            ]);

            foreach ($data['parts'] ?? [] as $partUsage) {
                $partId = $partUsage['part_id'] ?? null;

                Fp3PartUsage::create([
                    'fp3_request_id' => $locked->id,
                    'part_id' => $partId,
                    'part_name_manual' => $partId ? null : ($partUsage['part_name_manual'] ?? null),
                    'quantity' => $partUsage['quantity'],
                    'notes' => $partUsage['notes'] ?? null,
                ]);

                // A manual (unregistered) entry has no PartStock to move —
                // it's recorded for the report only, same as the paper
                // form's free-text "Kode" column would be for something not
                // in the system.
                if ($partId === null) {
                    continue;
                }

                $partStock = PartStock::where('part_id', $partId)
                    ->where('branch_id', $locked->branch_id)
                    ->first();

                if (! $partStock) {
                    throw new PartStockNotFoundException($partId, $locked->branch_id);
                }

                $this->stockMovements->record(
                    partStock: $partStock,
                    type: StockLedger::TYPE_ISSUE,
                    quantityChange: -$partUsage['quantity'],
                    user: $user,
                    notes: "FP3 {$locked->code}: {$data['executor_names']}",
                    reference: $locked,
                );
            }

            return $locked;
        });
    }

    public function cancel(Fp3Request $fp3Request, ?string $notes = null): Fp3Request
    {
        $fp3Request->update([
            'status' => Fp3Status::Cancelled,
            'disposition_note' => $notes,
        ]);

        return $fp3Request;
    }
}
