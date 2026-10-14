<?php

namespace App\Http\Requests;

use App\Models\TaskLibraryPart;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskLibraryPartRequest extends FormRequest
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
        /** @var TaskLibraryPart|null $part */
        $part = $this->route('taskLibraryPart');

        return [
            'task_library_checklist_item_id' => [
                'present',
                'nullable',
                'integer',
                Rule::exists('task_library_checklist_items', 'id')->where(
                    fn ($q) => $q->where('task_library_id', $part?->task_library_id)
                ),
            ],
        ];
    }
}
