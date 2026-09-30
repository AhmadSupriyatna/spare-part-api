<?php

namespace App\Http\Requests;

use App\Enums\Fp3Disposition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Engineer's report — everything on the paper form's "Diisi oleh
 * Departemen Pelaksana FP3" section. `parts.*.part_id` must be a part
 * already registered in the system (never free text); `executor_names`
 * stays free text on purpose (see Fp3Request's own docblock).
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
            'parts.*.part_id' => ['required', 'integer', 'exists:parts,id'],
            'parts.*.quantity' => ['required', 'integer', 'min:1'],
            'parts.*.notes' => ['nullable', 'string'],
        ];
    }
}
