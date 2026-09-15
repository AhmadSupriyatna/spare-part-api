<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartUnitActionRequest extends FormRequest
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
            'action' => ['required', Rule::in(['remove', 'reinstall'])],
            'requested_by_name' => ['required', 'string', 'max:255'],
            // Only asked for "reinstall" — a "remove" already knows where the
            // unit currently sits, no need for the technician to pick. No BOM
            // check here (unlike the breakdown flow's equipment picker) —
            // the authenticated "Pasang Part" button doesn't enforce one
            // either, and a unit can legitimately move to equipment whose
            // BOM was never filled in.
            'equipment_id' => [
                'required_if:action,reinstall',
                'prohibited_if:action,remove',
                'nullable',
                'integer',
                'exists:equipment,id',
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
