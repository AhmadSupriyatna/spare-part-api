<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Inventory Workspace's standalone "Pilih Supplier" action — sets
 * PartStock.supplier_id directly, independent of receiving stock (unlike
 * ReceiveStockDialog, which only ever sets it as a side effect of recording
 * a receipt).
 */
class UpdatePartStockSupplierRequest extends FormRequest
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
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $supplierId = $this->input('supplier_id');
            $partStock = $this->route('partStock');

            if (! $supplierId || ! $partStock) {
                return;
            }

            $belongsToSameBranch = Supplier::where('id', $supplierId)
                ->where('branch_id', $partStock->branch_id)
                ->exists();

            if (! $belongsToSameBranch) {
                $validator->errors()->add('supplier_id', 'Supplier tersebut bukan milik plant yang sama dengan stok ini.');
            }
        });
    }
}
