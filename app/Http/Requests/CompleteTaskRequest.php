<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompleteTaskRequest extends FormRequest
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
            'notes' => ['nullable', 'string'],
            'part_stock_id' => ['nullable', 'integer', 'exists:part_stocks,id'],
            'quantity_used' => ['nullable', 'integer', 'min:1', 'required_with:part_stock_id'],
        ];
    }
}
