<?php

namespace App\Http\Requests;

use App\Models\BudgetManualEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetManualEntryRequest extends FormRequest
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
            'type' => ['required', Rule::in([BudgetManualEntry::TYPE_PLANNED, BudgetManualEntry::TYPE_ACTUAL])],
            // Optional — a Line or a Machine (production or outside-line),
            // never both, but also fine to leave both empty for a
            // general/company-wide item.
            'line_id' => ['nullable', 'integer', 'exists:lines,id'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}
