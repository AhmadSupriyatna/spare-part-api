<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // The scan-page dropdown only ever offers equipment that uses this
            // part per its BOM (see PublicBreakdownController::equipmentForPartInBranch) —
            // this re-checks the same thing server-side so a direct API call
            // can't submit a part+equipment pairing that was never offered.
            'equipment_id' => [
                'required',
                'integer',
                'exists:equipment,id',
                Rule::exists('equipment_parts', 'equipment_id')
                    ->where(fn ($query) => $query->where('part_id', $this->input('part_id'))),
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
            'equipment_id.exists' => 'Equipment ini tidak terdaftar memakai part tersebut pada BOM.',
        ];
    }
}
