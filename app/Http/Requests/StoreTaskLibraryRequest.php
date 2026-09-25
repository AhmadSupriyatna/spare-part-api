<?php

namespace App\Http\Requests;

use App\Enums\TaskLibraryMaintenanceCategory;
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
            // Required for every new Task Library — no safe default among
            // the 3 categories (mirrors PartReplacementStrategy). Legacy
            // rows created before this field existed stay NULL rather than
            // being guessed at (see the migration).  No cross-field
            // compatibility rule against schedule_type is enforced here:
            // the business model doesn't clearly require e.g. INSPECTION to
            // forbid a calendar interval (a periodic inspection is a real
            // thing), so that's left as a UI hint, not a hard constraint.
            'maintenance_category' => ['required', Rule::enum(TaskLibraryMaintenanceCategory::class)],
            'schedule_type' => ['nullable', 'in:calendar,runtime'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'required_if:schedule_type,calendar'],
            'interval_hours' => ['nullable', 'integer', 'min:1', 'required_if:schedule_type,runtime'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'parts' => ['nullable', 'array'],
            'parts.*.part_id' => [
                'required',
                'integer',
                Rule::exists('part_installations', 'part_id')->where(
                    fn ($q) => $q->where('equipment_id', $this->route('equipment')?->id)->whereNull('removed_at')
                ),
            ],
            'parts.*.quantity_required' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
