<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Installs a part onto an equipment — a brand-new PartUnit by default, or an
 * existing "available" one (e.g. a Part Passport unit just received, or a
 * repaired unit) when `part_unit_id` is given. This is distinct from the
 * Supervisor-gated reinstall flow for an *unplanned* find (PartUnitActionRequest,
 * see PartUnitActionService/PartUnitScanPage) — that one exists for a
 * technician reinstalling a repaired unit with no prior record of where it's
 * going, so it needs oversight. Here the install is already the deliberate
 * action (this form, or a PM checklist scan), so no extra approval gate is
 * layered on top — PartLifecycleService::install() still guards against the
 * unit not actually belonging to `part_id`, or already being installed
 * elsewhere.
 */
class StorePartInstallationRequest extends FormRequest
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
            'part_id' => ['required', 'integer', 'exists:parts,id'],
            'part_unit_id' => ['nullable', 'integer', 'exists:part_units,id'],
            'installed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
