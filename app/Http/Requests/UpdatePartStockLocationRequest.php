<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePartStockLocationRequest extends FormRequest
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
            'location_id' => ['required', 'integer', 'exists:locations,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $locationId = $this->input('location_id');
            $partStock = $this->route('partStock');

            if (! $locationId || ! $partStock) {
                return;
            }

            $belongsToSameBranch = Location::where('id', $locationId)
                ->where('branch_id', $partStock->branch_id)
                ->exists();

            if (! $belongsToSameBranch) {
                $validator->errors()->add('location_id', 'Lokasi tersebut bukan milik cabang yang sama dengan stok ini.');
            }
        });
    }
}
