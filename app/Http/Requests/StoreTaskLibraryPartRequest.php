<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskLibraryPartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['needs_replacement' => false]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'part_id' => [
                'required', 'integer',
                Rule::exists('part_installations', 'part_id')->where(
                    fn ($q) => $q
                        ->where('equipment_id', $this->route('taskLibrary')?->equipment_id)
                        ->whereNull('removed_at')
                ),
                Rule::unique('task_library_parts', 'part_id')
                    ->where('task_library_id', $this->route('taskLibrary')?->id),
            ],
            'needs_replacement' => ['sometimes', 'boolean'],
            'quantity_required' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
