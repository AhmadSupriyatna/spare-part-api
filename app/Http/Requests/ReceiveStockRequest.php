<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReceiveStockRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $supplierId = $this->input('supplier_id');
            $partStock = $this->route('partStock');

            if ($supplierId && $partStock) {
                $belongsToSameBranch = Supplier::where('id', $supplierId)
                    ->where('branch_id', $partStock->branch_id)
                    ->exists();

                if (! $belongsToSameBranch) {
                    $validator->errors()->add('supplier_id', 'Supplier tersebut bukan milik cabang yang sama dengan stok ini.');
                }
            }
        });
    }
}
