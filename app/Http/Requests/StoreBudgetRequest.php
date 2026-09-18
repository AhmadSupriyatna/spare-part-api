<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'category' => ['nullable', 'string', 'max:255'],
            'year' => [
                'required',
                'integer',
                'min:2020',
                'max:2100',
                Rule::unique('budgets')->where(fn ($query) => $query
                    ->where('branch_id', $branch?->id)
                    ->where('category', $this->input('category') ?: '')),
            ],
            'planned_amount' => ['required', 'numeric', 'min:0'],
            'corrective_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
