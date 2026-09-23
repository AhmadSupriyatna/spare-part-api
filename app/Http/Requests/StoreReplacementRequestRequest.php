<?php

namespace App\Http\Requests;

use App\Models\PartInstallation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReplacementRequestRequest extends FormRequest
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
            // The scan-page dropdown only ever offers equipment that actually
            // has this part installed right now (see
            // PublicBreakdownController::equipmentForPartInBranch) — this
            // re-checks the same thing server-side so a direct API call can't
            // submit a part+equipment pairing that was never offered.
            'equipment_id' => [
                'required',
                'integer',
                'exists:equipment,id',
                Rule::exists('part_installations', 'equipment_id')->where(function ($query) {
                    $query->where('part_id', $this->input('part_id'))->whereNull('removed_at');
                }),
            ],
            'requested_by_name' => ['required', 'string', 'max:255'],
            'quantity_used' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'equipment_id.exists' => 'Part ini tidak sedang terpasang di equipment tersebut.',
        ];
    }

    /**
     * quantity_used can never exceed how many units of this part are
     * actually active on this equipment right now — otherwise the request
     * would sit as "Pending" only to be un-approvable later (see
     * PartReplacementService::resolveOldInstallations()). Checked here, at
     * creation time, so an invalid request is never even stored. Skipped if
     * part_id/equipment_id themselves already failed validation, so this
     * doesn't compound a confusing second error on top of the first.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['part_id', 'equipment_id', 'quantity_used'])) {
                return;
            }

            $activeCount = PartInstallation::query()
                ->where('equipment_id', $this->input('equipment_id'))
                ->where('part_id', $this->input('part_id'))
                ->whereNull('removed_at')
                ->count();

            $requested = (int) $this->input('quantity_used');

            if ($requested > $activeCount) {
                $validator->errors()->add(
                    'quantity_used',
                    "Equipment ini memiliki {$activeCount} unit part aktif, tetapi quantity yang diminta {$requested}."
                );
            }
        });
    }
}
