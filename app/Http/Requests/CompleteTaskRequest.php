<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteTaskRequest extends FormRequest
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
        /** @var Task|null $task */
        $task = $this->route('task');

        // PM tasks — scheduled from either a Task Library recipe or a
        // lifetime-worn-part flag — carry a fixed parts checklist instead of
        // the single part_stock_id/quantity_used pair every other task uses:
        // each planned part must be accounted for, replaced (with a
        // quantity) or not (with a mandatory reason).
        if ($task && $task->partChecks()->exists()) {
            return [
                'notes' => ['nullable', 'string'],
                'checks' => ['required', 'array', 'min:1'],
                // One TaskPartCheck row is now always exactly one address
                // (see PmSchedulingService::schedule()) — a task can have
                // several rows sharing the same part_id, so `id` (not
                // part_id) is what identifies which row this entry updates.
                'checks.*.id' => [
                    'required', 'integer',
                    Rule::exists('task_part_checks', 'id')->where('task_id', $task?->id),
                ],
                'checks.*.part_id' => ['required', 'integer', 'exists:parts,id'],
                'checks.*.is_replaced' => ['required', 'boolean'],
                'checks.*.quantity_used' => [
                    'nullable', 'integer', 'min:1',
                    'required_if:checks.*.is_replaced,true',
                ],
                'checks.*.reason' => [
                    'nullable', 'string', 'max:1000',
                    'required_if:checks.*.is_replaced,false',
                ],
                'checks.*.part_unit_id' => ['nullable', 'integer', 'exists:part_units,id'],
                'checks.*.old_installation_id' => ['nullable', 'integer', 'exists:part_installations,id'],
            ];
        }

        return [
            'notes' => ['nullable', 'string'],
            'part_stock_id' => ['nullable', 'integer', 'exists:part_stocks,id'],
            'quantity_used' => ['nullable', 'integer', 'min:1', 'required_with:part_stock_id'],
        ];
    }
}
