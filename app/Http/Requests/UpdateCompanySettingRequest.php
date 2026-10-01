<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanySettingRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'avg_weekly_operating_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            // Accepted from anyone, but only ever persisted for a Superadmin
            // — see CompanySettingController::update().
            'app_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'app_logo' => ['nullable', 'image', 'max:2048'],
            'kwh_input_mode' => ['sometimes', 'in:reading,direct'],
        ];
    }
}
