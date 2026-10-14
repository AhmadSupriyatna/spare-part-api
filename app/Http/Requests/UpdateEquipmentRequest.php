<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * No `code` here on purpose — it's system-generated once at creation
     * and must NOT change automatically when the name is edited (business
     * rule), so it's simply not an editable field via this endpoint.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['nullable', 'string', Rule::exists('asset_categories', 'name')->where('type', 'equipment')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
