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

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'part_id' => [
                'required', 'integer', 'exists:parts,id',
                Rule::unique('task_library_parts', 'part_id')
                    ->where('task_library_id', $this->route('taskLibrary')?->id),
            ],
            'quantity_required' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
