<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

/**
 * Restoring the database replaces every table in the uploaded file —
 * the single widest-blast-radius action in this app, wider even than
 * archiving/deleting one Branch — so it gets the same password
 * re-confirmation as every other cascading-delete action
 * (see DestroyWithPasswordRequest), plus its own file validation.
 */
class RestoreDatabaseRequest extends FormRequest
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
            'backup_file' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:51200'],
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
