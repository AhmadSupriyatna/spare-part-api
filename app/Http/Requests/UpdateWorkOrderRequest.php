<?php

namespace App\Http\Requests;

use App\Enums\ScheduleType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkOrderRequest extends FormRequest
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
            'part_id' => ['nullable', 'integer', 'exists:parts,id'],
            'schedule_type' => ['sometimes', 'required', Rule::enum(ScheduleType::class)],
            'interval_days' => ['nullable', 'integer', 'min:1'],
            'interval_hours' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
