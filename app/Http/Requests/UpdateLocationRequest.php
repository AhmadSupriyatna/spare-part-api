<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
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
        /** @var \App\Models\Location $location */
        $location = $this->route('location');

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('locations', 'code')->where('branch_id', $location->branch_id)->ignore($location),
            ],
            'rack' => ['sometimes', 'required', 'string', 'max:50'],
            'bin' => ['sometimes', 'required', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
