<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A Bin's rack/level/bin_number are fixed at creation — only description,
 * active status, and now `code` can be changed afterward. To "move" a bin
 * to a different rack level, delete it and add a new one there instead.
 */
class UpdateLocationRequest extends FormRequest
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
        $location = $this->route('location');
        $branchId = $location?->branch_id;

        return [
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('locations', 'code')
                    ->where(fn ($query) => $query->where('branch_id', $branchId))
                    ->ignore($location?->id),
            ],
        ];
    }
}
