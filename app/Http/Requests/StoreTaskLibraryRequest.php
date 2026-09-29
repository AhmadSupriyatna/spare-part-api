<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskLibraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['is_active' => true]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'schedule_type' => ['nullable', 'in:calendar,runtime'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'required_if:schedule_type,calendar'],
            'interval_hours' => ['nullable', 'integer', 'min:1', 'required_if:schedule_type,runtime'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            // No parts.*.quantity_required here on purpose — same reasoning
            // as StoreTaskLibraryPartRequest: it's always the part's active-
            // installation count, computed server-side in
            // TaskLibraryController::store(), never client-supplied.
            'parts' => ['nullable', 'array'],
            'parts.*.part_id' => [
                'required',
                'integer',
                Rule::exists('part_installations', 'part_id')->where(
                    fn ($q) => $q->where('equipment_id', $this->route('equipment')?->id)->whereNull('removed_at')
                ),
            ],
        ];
    }
}
