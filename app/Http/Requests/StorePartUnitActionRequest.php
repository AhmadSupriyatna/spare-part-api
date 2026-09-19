<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A bare "remove" (pull a unit off equipment with no replacement specified
 * in the same action) used to be allowed here — a real safety gap, since
 * it let a part come off a running machine without any accompanying
 * install being reviewed at the same time, unlike Breakdown
 * (PartReplacementRequest, always remove+install together) and the WO/PM
 * checklist (also always remove+install together, self-service). Closed
 * 2026-09-19: only "reinstall" (putting a repaired unit back into service)
 * can be submitted now. The `remove` enum case stays on PartUnitActionType
 * and PartUnitActionService purely so the handful of already-approved
 * historical requests still display and cast correctly — nothing creates
 * new ones anymore.
 */
class StorePartUnitActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['reinstall'])],
            'requested_by_name' => ['required', 'string', 'max:255'],
            // No BOM check here (unlike the breakdown flow's equipment
            // picker) — the authenticated "Pasang Part" button doesn't
            // enforce one either, and a unit can legitimately move to
            // equipment whose BOM was never filled in.
            'equipment_id' => ['required', 'integer', 'exists:equipment,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
