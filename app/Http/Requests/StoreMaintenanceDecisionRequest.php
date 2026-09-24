<?php

namespace App\Http\Requests;

use App\Enums\FindingSourceType;
use App\Enums\MaintenanceDecisionType;
use App\Models\Finding;
use App\Models\PartInstallation;
use App\Models\TaskLibrary;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMaintenanceDecisionRequest extends FormRequest
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
            'decision' => ['required', Rule::enum(MaintenanceDecisionType::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'follow_up_task_library_id' => ['nullable', 'integer', 'exists:task_libraries,id'],
            'follow_up_due_date' => ['nullable', 'date'],
            'follow_up_title' => ['nullable', 'string', 'max:255'],
            'follow_up_notes' => ['nullable', 'string', 'max:2000'],
            'follow_up_quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Cross-field/ownership checks that need the route-bound Finding —
     * mirrors how StoreReplacementRequestRequest checks branch/quantity
     * ownership here rather than in rules(). The "Finding already has a
     * Decision" state check is deliberately NOT here: that's a resource
     * lifecycle conflict handled by MaintenanceDecisionService (locked,
     * race-safe, 409), the same way ReplacementRequestAlreadyReviewedException
     * is handled for the existing Approval flow rather than in its FormRequest.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Finding $finding */
            $finding = $this->route('finding');
            $decision = MaintenanceDecisionType::from($this->input('decision'));

            $wantsFollowUpTask = $this->filled('follow_up_task_library_id') || $this->filled('follow_up_title');

            if ($decision === MaintenanceDecisionType::ScheduleMaintenance && ! $wantsFollowUpTask) {
                $validator->errors()->add(
                    'follow_up_title',
                    'Schedule Maintenance wajib memiliki follow-up task — pilih Task Library atau isi judul ad-hoc.'
                );

                return;
            }

            if (in_array($decision, [MaintenanceDecisionType::Monitor, MaintenanceDecisionType::ScheduleMaintenance], true) && $wantsFollowUpTask) {
                if (! $this->filled('follow_up_due_date')) {
                    $validator->errors()->add('follow_up_due_date', 'Follow-up task wajib memiliki due date.');

                    return;
                }

                if ($this->filled('follow_up_task_library_id')) {
                    $library = TaskLibrary::find($this->input('follow_up_task_library_id'));

                    if ($library && $library->equipment_id !== $finding->equipment_id) {
                        $validator->errors()->add(
                            'follow_up_task_library_id',
                            'Task Library ini tidak sesuai dengan equipment pada Finding ini.'
                        );

                        return;
                    }
                }
            }

            if ($decision === MaintenanceDecisionType::ReplacePart) {
                if ($finding->part_id === null) {
                    $validator->errors()->add(
                        'decision',
                        'Finding ini tidak memiliki part — tidak dapat diputuskan sebagai Ganti Part.'
                    );

                    return;
                }

                $activeCount = PartInstallation::query()
                    ->where('equipment_id', $finding->equipment_id)
                    ->where('part_id', $finding->part_id)
                    ->whereNull('removed_at')
                    ->count();

                $quantity = $this->filled('follow_up_quantity')
                    ? (int) $this->input('follow_up_quantity')
                    : $this->defaultReplacementQuantity($finding);

                if ($quantity > $activeCount) {
                    $validator->errors()->add(
                        'follow_up_quantity',
                        "Equipment ini memiliki {$activeCount} unit part aktif, tetapi quantity yang diminta {$quantity}."
                    );
                }
            }
        });
    }

    /**
     * Mirrors MaintenanceDecisionService::resolveReplacementQuantity() —
     * kept here too (not shared) since a FormRequest validating against a
     * Service would be an unusual layering for this codebase; both read the
     * same authoritative TaskPartCheck.quantity_planned.
     */
    private function defaultReplacementQuantity(Finding $finding): int
    {
        if ($finding->source_type === FindingSourceType::TaskPartCheck && $finding->taskPartCheck) {
            return $finding->taskPartCheck->quantity_planned;
        }

        return 1;
    }
}
