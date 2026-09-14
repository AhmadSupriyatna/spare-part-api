<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreReplacementRequestRequest extends FormRequest
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
            'equipment_id' => ['required', 'integer', 'exists:equipment,id'],
            'requested_by_name' => ['required', 'string', 'max:255'],
            'quantity_used' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
