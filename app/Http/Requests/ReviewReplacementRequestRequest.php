<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReviewReplacementRequestRequest extends FormRequest
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
            'notes' => ['nullable', 'string', 'max:1000'],
            // Which currently-active installation(s) of this part on this
            // equipment are being closed out — see
            // PartReplacementService::resolveOldInstallations() for the full
            // rule (mandatory and exactly quantity_used long once that's > 1).
            // `distinct` rejects picking the same unit twice at the
            // validation layer too, not just in the service.
            'old_installation_ids' => ['sometimes', 'array'],
            'old_installation_ids.*' => ['integer', 'distinct', 'exists:part_installations,id'],
        ];
    }
}
