<?php

namespace App\Http\Requests;

use App\Enums\FindingResult;
use App\Enums\FindingSeverity;
use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            $rules = [
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

                // Optional structured observation, independent of
                // is_replaced — never required, and never carries
                // source_type/source_id/equipment_id/part_id/reported_by/
                // reported_at: those are always server-derived from the
                // authoritative TaskPartCheck row (see
                // MaintenanceFindingService::createFromTaskPartCheck()).
                'checks.*.finding' => ['sometimes', 'array'],
                'checks.*.finding.description' => ['required_with:checks.*.finding', 'string', 'max:2000'],
                'checks.*.finding.result' => ['required_with:checks.*.finding', Rule::enum(FindingResult::class)],
                // NOT required_unless:...,pass here — that rule treats a
                // wholly absent `finding` (the normal case; see class docblock
                // above) the same as a present-but-non-"pass" one, so
                // omitting `finding` entirely made severity required. The
                // "required only when finding is actually sent and isn't a
                // pass" condition instead lives in withValidator() below,
                // where absence and presence can be told apart.
                'checks.*.finding.severity' => ['nullable', Rule::enum(FindingSeverity::class)],
                'checks.*.finding.recommendation' => ['nullable', 'string', 'max:2000'],
            ];
        } else {
            $rules = [
                'notes' => ['nullable', 'string'],
                'part_stock_id' => ['nullable', 'integer', 'exists:part_stocks,id'],
                'quantity_used' => ['nullable', 'integer', 'min:1', 'required_with:part_stock_id'],
            ];
        }

        // Condition-check items (Kondisi Baik/Tidak) are independent of
        // which branch above ran — a task can carry both a part checklist
        // and checklist items, or (a checklist-only Task Library) just this.
        if ($task && $task->checklistItems()->exists()) {
            $rules['checklist_items'] = ['required', 'array', 'min:1'];
            $rules['checklist_items.*.id'] = [
                'required', 'integer',
                Rule::exists('task_checklist_items', 'id')->where('task_id', $task->id),
            ];
            $rules['checklist_items.*.condition_ok'] = ['required', 'boolean'];
            $rules['checklist_items.*.notes'] = ['nullable', 'string', 'max:1000'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        // sometimes()'s callback receives ($payload, $item) - the whole
        // request as the first argument, and (since this rule key doesn't
        // end in ".*") the value one segment up from the rule itself as the
        // second: here that's `checks.N.finding` (or null when that check
        // has no `finding` key at all). Only $item is relevant.
        $validator->sometimes(
            'checks.*.finding.severity',
            ['required'],
            fn ($payload, $finding) => ! is_null($finding) && ($finding['result'] ?? null) !== FindingResult::Pass->value,
        );
    }
}
