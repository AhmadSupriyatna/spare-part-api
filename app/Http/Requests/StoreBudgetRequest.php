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
            'items' => ['nullable', 'array'],
            'items.*.part_id' => ['required', 'integer', 'exists:parts,id'],
            'items.*.estimated_lifetime_percent' => ['required', 'numeric', 'min:1'],
            'items.*.price_increase_percent' => ['nullable', 'numeric', 'min:0'],
            'corrective_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Per-part lifetime-forecast items only make sense for a LifeBased
     * budget — every other strategy has no automatic forecast to build
     * items from (see BudgetForecastService::ratesFor), so submitting items
     * against a non-LifeBased strategy would otherwise silently persist as
     * an always-zero-cost row. Reject it outright instead.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $strategy = $this->input('replacement_strategy');
            $items = $this->input('items', []);

            if ($strategy === PartReplacementStrategy::LifeBased->value && empty($items)) {
                $validator->errors()->add('items', 'Pilih minimal satu part untuk budget Berdasarkan Umur Pakai.');
            }

            if ($strategy !== PartReplacementStrategy::LifeBased->value && ! empty($items)) {
                $validator->errors()->add('items', 'Item per-part hanya berlaku untuk strategi Berdasarkan Umur Pakai.');
            }
        });
    }
}
