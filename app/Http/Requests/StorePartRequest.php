<?php

namespace App\Http\Requests;

use App\Enums\PartReplacementStrategy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // replacement_strategy is deliberately NOT defaulted here — there is
        // no safe catch-all strategy (see PartReplacementStrategy), so a new
        // Part must have one explicitly chosen. The 'required' rule below is
        // what actually enforces that; this only fills in is_active.
        $this->mergeIfMissing([
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'item_master_no' => ['required', 'string', 'max:100', 'unique:parts,item_master_no'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit' => ['required', 'string', 'max:50', 'exists:units,name'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'estimated_lifetime_hours' => ['nullable', 'integer', 'min:1'],
            'replacement_strategy' => ['required', Rule::enum(PartReplacementStrategy::class)],
            'image' => ['nullable', 'image', 'max:5120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
