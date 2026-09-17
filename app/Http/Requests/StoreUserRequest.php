<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', Password::min(8)],
            'role' => ['required', new Enum(UserRole::class)],
            'avatar' => ['nullable', 'image', 'max:5120'],
            'branch_ids' => [
                Rule::requiredIf(fn () => $this->input('role') !== UserRole::Superadmin->value),
                'array',
            ],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ];
    }
}
