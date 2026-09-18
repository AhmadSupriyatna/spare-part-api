<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * This endpoint always installs a brand-new PartUnit — it deliberately does
 * NOT accept a `part_unit_id` for reinstalling an existing "available"
 * (i.e. repaired) unit. That used to be allowed here and let anyone with
 * Admin Spare Part reinstall a repaired unit with zero oversight, bypassing
 * the Supervisor approval that the equivalent QR-scan reinstall flow
 * (PartUnitActionRequest, see PartUnitActionService) always goes through.
 * Reinstalling a repaired unit must go through that QR flow now; this form
 * is only for genuinely new installations (including initial data setup).
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
            'installed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
