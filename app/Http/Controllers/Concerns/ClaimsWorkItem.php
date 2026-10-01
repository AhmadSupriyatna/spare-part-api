<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared "claim on first action" rule, used wherever a work item (a Task/WO,
 * an Fp3Request) is handed to whoever acts on it first rather than assigned
 * up front: unclaimed + allowed role -> claims it (sets $claimField to the
 * acting user's id); already claimed -> only that user or a superadmin may
 * keep acting on it. Previously hand-duplicated identically in
 * TaskController::ensureCanWork() and Fp3RequestController::ensureCanWork()
 * (same logic, only the claim field name and the Indonesian wording in the
 * abort messages differed) — any future work-item type with this same claim
 * behavior reuses this instead of copying it a third time.
 */
trait ClaimsWorkItem
{
    private function ensureCanClaim(Request $request, Model $item, string $claimField, string $itemLabel): void
    {
        $user = $request->user();

        if ($item->{$claimField} !== null) {
            abort_unless(
                $item->{$claimField} === $user->id || $user->hasRole('superadmin'),
                403,
                "{$itemLabel} ini sudah diklaim oleh pengguna lain."
            );

            return;
        }

        abort_unless(
            $user->hasAnyRole(['engineer', 'supervisor', 'superadmin']),
            403,
            "Hanya Supervisor dan Engineer yang bisa mengerjakan {$itemLabel}."
        );

        $item->update([$claimField => $user->id]);
    }
}
