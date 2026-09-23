<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * "Terima Barang" for a part that may not have a part_stocks row for this
 * branch yet — the standalone Stock In page lists the whole part catalog
 * (not just parts already stocked here), so this is the first entry point
 * that can bring a part into a branch's stock for the very first time.
 */
class StorePartStockRequest extends FormRequest
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
            'part_id' => ['required', 'integer', 'exists:parts,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'total_price' => ['required', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $supplierId = $this->input('supplier_id');
            $branch = $this->route('branch');

            if ($supplierId && $branch) {
                $belongsToSameBranch = Supplier::where('id', $supplierId)
                    ->where('branch_id', $branch->id)
                    ->exists();

                if (! $belongsToSameBranch) {
                    $validator->errors()->add('supplier_id', 'Supplier tersebut bukan milik plant yang sama.');
                }
            }
        });
    }
}
