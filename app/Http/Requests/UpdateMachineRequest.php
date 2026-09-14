<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMachineRequest extends FormRequest
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
        /** @var \App\Models\Machine $machine */
        $machine = $this->route('machine');

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('machines', 'code')->where('line_id', $machine->line_id)->ignore($machine),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
