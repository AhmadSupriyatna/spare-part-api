<?php

namespace App\Http\Requests;

use App\Enums\PartReplacementStrategy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * replacement_strategy/year identify which budget this is and are
     * locked once it exists (delete and recreate instead of moving a
     * budget to a different strategy/year) — only the per-part estimates
     * and the corrective top-up stay editable.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['sometimes', 'array'],
            'items.*.part_id' => ['required_with:items', 'integer', 'exists:parts,id'],
            'items.*.estimated_lifetime_percent' => ['required_with:items', 'numeric', 'min:1'],
            'items.*.price_increase_percent' => ['nullable', 'numeric', 'min:0'],
            'corrective_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Same LifeBased-only guard as StoreBudgetRequest, but keyed off the
     * existing budget's own (immutable) strategy rather than form input.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $budget = $this->route('budget');
            $items = $this->input('items');

            if ($budget && $items !== null && $budget->replacement_strategy !== PartReplacementStrategy::LifeBased) {
                $validator->errors()->add('items', 'Item per-part hanya berlaku untuk budget Berdasarkan Umur Pakai.');
            }
        });
    }
}
