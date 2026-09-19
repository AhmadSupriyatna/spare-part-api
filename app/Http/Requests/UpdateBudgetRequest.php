<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * category/year identify which budget this is and are locked once it
     * exists (delete and recreate instead of moving a budget to a
     * different category/year) — only the per-part estimates and the
     * corrective top-up stay editable.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.part_id' => ['required_with:items', 'integer', 'exists:parts,id'],
            'items.*.estimated_lifetime_percent' => ['required_with:items', 'numeric', 'min:1'],
            'items.*.price_increase_percent' => ['nullable', 'numeric', 'min:0'],
            'corrective_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
