<?php

namespace App\Http\Requests;

use App\Enums\ScheduleType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
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
            'part_id' => ['nullable', 'integer', 'exists:parts,id'],
            'schedule_type' => ['required', Rule::enum(ScheduleType::class)],
            'interval_days' => ['required_if:schedule_type,calendar', 'nullable', 'integer', 'min:1'],
            'interval_hours' => ['required_if:schedule_type,runtime', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
