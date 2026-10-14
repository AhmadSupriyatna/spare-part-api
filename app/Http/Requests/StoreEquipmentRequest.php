<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['is_active' => true]);
    }

    /**
     * No `code` here on purpose — it's system-generated from `name` in
     * EquipmentController::store() (see Equipment::generateCode()), never
     * client-supplied.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', Rule::exists('asset_categories', 'name')->where('type', 'equipment')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
