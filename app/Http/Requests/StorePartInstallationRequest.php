<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartInstallationRequest extends FormRequest
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
            'part_id' => ['required', 'integer', 'exists:parts,id'],
            // Omit to install a brand-new unit; pass an existing unit's id
            // (must belong to this part and be "available", i.e. repaired
            // and not currently mounted anywhere) to reinstall it instead.
            'part_unit_id' => [
                'nullable', 'integer',
                Rule::exists('part_units', 'id')->where(function ($query) {
                    $query->where('part_id', $this->input('part_id'))->where('status', 'available');
                }),
            ],
            'installed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
