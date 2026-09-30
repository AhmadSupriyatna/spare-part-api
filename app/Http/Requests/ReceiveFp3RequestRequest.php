<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Claiming an FP3 request ("Diterima tanggal" on the paper form) — an
 * optional due_date lets the Engineer schedule it for later instead of
 * working it right away; leaving it blank means "kerjakan sekarang".
 */
class ReceiveFp3RequestRequest extends FormRequest
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
            'due_date' => ['nullable', 'date'],
        ];
    }
}
