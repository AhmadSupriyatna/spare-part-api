<?php

namespace App\Http\Requests;

use App\Enums\PartReplacementStrategy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBudgetRequest extends FormRequest
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
        $branch = $this->route('branch');

        return [
            'replacement_strategy' => ['required', Rule::enum(PartReplacementStrategy::class)],
            'year' => [
                'required',
                'integer',
                'min:2020',
                'max:2100',
                Rule::unique('budgets')->where(fn ($query) => $query
                    ->where('branch_id', $branch?->id)
                    ->where('replacement_strategy', $this->input('replacement_strategy'))),
            ],
            // Only meaningful (and required) for a LifeBased budget — one
            // assumption for the whole budget now, not tuned per part.
            'global_lifetime_percent' => ['required_if:replacement_strategy,life_based', 'nullable', 'numeric', 'min:1'],
            // Price inflation applies regardless of strategy.
            'global_price_increase_percent' => ['nullable', 'numeric', 'min:0'],
            'items' => ['nullable', 'array'],
            'items.*.part_id' => ['required', 'integer', 'exists:parts,id'],
            'items.*.estimated_failure_count_per_year' => ['required_if:replacement_strategy,failure_based', 'nullable', 'numeric', 'min:0'],
            'items.*.replacement_probability_percent' => ['required_if:replacement_strategy,scheduled', 'nullable', 'numeric', 'min:0', 'max:100'],
            'corrective_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Every strategy now has an automatic forecast, so at least one part
     * must be picked whichever strategy this is — an empty budget with
     * nothing but a manual corrective top-up is still allowed by not
     * requiring items at all, this only rejects the accidental empty state
     * where the drawer's part list was shown but nothing got checked.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $strategy = $this->input('replacement_strategy');
            $items = $this->input('items', []);

            if ($strategy === PartReplacementStrategy::LifeBased->value && empty($items)) {
                $validator->errors()->add('items', 'Pilih minimal satu part untuk budget Berdasarkan Umur Pakai.');
            }
        });
    }
}
