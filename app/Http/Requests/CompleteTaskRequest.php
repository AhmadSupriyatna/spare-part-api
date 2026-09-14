<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        /** @var \App\Models\Task|null $task */
        $task = $this->route('task');

        // PM tasks scheduled from a Task Library carry a fixed parts checklist
        // instead of the single part_stock_id/quantity_used pair every other
        // task uses — each planned part must be accounted for: replaced (with
        // a quantity) or not (with a mandatory reason).
        if ($task && $task->task_library_id) {
            return [
                'notes' => ['nullable', 'string'],
                'checks' => ['required', 'array', 'min:1'],
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
            ];
        }

        return [
            'notes' => ['nullable', 'string'],
            'part_stock_id' => ['nullable', 'integer', 'exists:part_stocks,id'],
            'quantity_used' => ['nullable', 'integer', 'min:1', 'required_with:part_stock_id'],
        ];
    }
}
