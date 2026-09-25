<?php

namespace App\Http\Requests;

use App\Enums\TaskLibraryMaintenanceCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskLibraryRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // 'sometimes' (not always 'required') so an edit that doesn't
            // touch this field can't be blocked by it — but if the client
            // does send it (e.g. finally classifying a legacy NULL row via
            // the edit form), it must be a real value, never cleared back
            // to null once set.
            'maintenance_category' => ['sometimes', 'required', Rule::enum(TaskLibraryMaintenanceCategory::class)],
            'schedule_type' => ['nullable', 'in:calendar,runtime'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'required_if:schedule_type,calendar'],
            'interval_hours' => ['nullable', 'integer', 'min:1', 'required_if:schedule_type,runtime'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
