<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

/**
 * Shared by the Line/Machine/Equipment destroy endpoints — deleting any of
 * these cascades to everything under it (machines, equipment, work orders,
 * tasks, task libraries, part installations...), so it requires the acting
 * user to re-enter their own password, not just a confirmation dialog.
 */
class DestroyWithPasswordRequest extends FormRequest
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
            'password' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    if (! Hash::check($value, $this->user()->password)) {
                        $fail('Password yang Anda masukkan salah.');
                    }
                },
            ],
        ];
    }
}
