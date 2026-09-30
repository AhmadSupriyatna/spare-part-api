<?php

namespace App\Http\Requests;

use App\Enums\Fp3Disposition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Engineer's report — everything on the paper form's "Diisi oleh
 * Departemen Pelaksana FP3" section. Each part row is either a
 * system-registered Part (`part_id`, still issues stock) or a free-text
 * name for something not registered at all (`part_name_manual`, no stock
 * movement) — exactly one of the two, never both, never neither (matches
 * Fp3PartUsage::booted()'s XOR check, re-validated here so a bad request
 * fails with a normal 422 instead of the model throwing mid-transaction).
 * `executor_names` stays free text on purpose (see Fp3Request's own
 * docblock).
 */
class CompleteFp3RequestRequest extends FormRequest
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
            'work_description' => ['required', 'string'],
            'executor_names' => ['required', 'string', 'max:255'],
            'area_condition_after' => ['required', 'string'],
            'disposition' => ['required', Rule::in(array_column(Fp3Disposition::cases(), 'value'))],
            'disposition_note' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'parts' => ['nullable', 'array'],
            'parts.*.part_id' => ['nullable', 'integer', 'exists:parts,id'],
            'parts.*.part_name_manual' => ['nullable', 'string', 'max:255'],
            'parts.*.quantity' => ['required', 'integer', 'min:1'],
            'parts.*.notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->input('parts', []) as $index => $row) {
                $hasPart = ! empty($row['part_id']);
                $hasManual = ! empty($row['part_name_manual']);

                if ($hasPart === $hasManual) {
                    $validator->errors()->add(
                        "parts.{$index}",
                        'Pilih part dari sistem atau isi nama manual — tidak boleh keduanya atau tidak sama sekali.'
                    );
                }
            }
        });
    }
}
