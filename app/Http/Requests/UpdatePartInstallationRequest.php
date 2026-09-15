<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePartInstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only `notes` is editable after the fact. `part_id`, `part_unit_id`,
     * and `installed_at` define the installation's identity and lifetime
     * math (installed_at has a paired installed_at_runtime_hours snapshot
     * that can't be retroactively recomputed) — those are set once at
     * install time and never changed here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string'],
        ];
    }
}
