<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `code` is optional — when omitted, LocationController::store() falls
 * back to the server-generated rack/level/bin value. When given, it must
 * be unique within the branch (two branches may reuse the same code).
 */
class StoreLocationRequest extends FormRequest
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
        $branchId = $this->route('rackLevel')?->rack?->branch_id;

        return [
            'description' => ['nullable', 'string'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('locations', 'code')->where(fn ($query) => $query->where('branch_id', $branchId)),
            ],
        ];
    }
}
