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
     * replacement_strategy/year identify which budget this is and are
     * locked once it exists (delete and recreate instead of moving a
     * budget to a different strategy/year) — only the global assumptions,
     * per-part estimates, and the corrective top-up stay editable.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $budget = $this->route('budget');
        $strategy = $budget?->replacement_strategy?->value;

        return [
            'global_lifetime_percent' => $strategy === 'life_based'
                ? ['required', 'numeric', 'min:1']
                : ['prohibited'],
            'global_price_increase_percent' => ['nullable', 'numeric', 'min:0'],
            'items' => ['sometimes', 'array'],
            'items.*.part_id' => ['required_with:items', 'integer', 'exists:parts,id'],
            'items.*.estimated_failure_count_per_year' => $strategy === 'failure_based'
                ? ['required_with:items', 'numeric', 'min:0']
                : ['prohibited'],
            'items.*.replacement_probability_percent' => $strategy === 'scheduled'
                ? ['required_with:items', 'numeric', 'min:0', 'max:100']
                : ['prohibited'],
            'corrective_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
