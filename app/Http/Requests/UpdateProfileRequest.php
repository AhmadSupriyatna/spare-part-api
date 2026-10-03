<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Self-service "Edit Profil" — any authenticated user changing their OWN
 * name/password, distinct from UpdateUserRequest (superadmin editing
 * someone else's account, no re-auth needed there since the admin already
 * has elevated trust). current_password is required only when actually
 * changing the password, via Laravel's built-in `current_password` rule
 * (validates against the authenticated guard's own credentials).
 */
class UpdateProfileRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'current_password' => ['required_with:password', 'current_password'],
            'password' => ['nullable', Password::min(8)],
            'avatar' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
