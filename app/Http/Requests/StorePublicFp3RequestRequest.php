<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The public (no login) "Ajukan FP3" QR-scan submission — Nama, Departemen,
 * Deskripsi, dan Foto, exactly the 4 fields asked for. No equipment/machine/
 * line at all on purpose: an FP3 request can be for anything, registered or
 * not.
 */
class StorePublicFp3RequestRequest extends FormRequest
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
            'requester_name' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'photo' => ['required', 'image', 'max:5120'],
        ];
    }
}
