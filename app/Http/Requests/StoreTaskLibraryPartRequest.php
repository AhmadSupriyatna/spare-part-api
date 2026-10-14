<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * No `quantity_required` here on purpose — it's always the part's current
 * active-installation count on this equipment
 * (Equipment::activeInstallationCountForPart()), computed server-side in
 * TaskLibraryPartController::store(), never a client-supplied number.
 */
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
            'task_library_checklist_item_id' => [
                'nullable',
                'integer',
                Rule::exists('task_library_checklist_items', 'id')->where(
                    fn ($q) => $q->where('task_library_id', $this->route('taskLibrary')?->id)
                ),
            ],
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
            'notes' => ['nullable', 'string'],
        ];
    }
}
