<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddLineRuntimeRequest extends FormRequest
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
            // The technician reads the line's hour meter and reports the
            // absolute value shown on it — not a delta — since that's what
            // they can actually see. The server computes the delta against
            // the last recorded reading (see LineController::addRuntime()).
            'current_reading' => [
                'required',
                'integer',
                'min:0',
                function ($attribute, $value, $fail) {
                    $line = $this->route('line');
                    if ($line && $value < $line->runtime_hours) {
                        $fail("Reading tidak boleh kurang dari jam operasi saat ini ({$line->runtime_hours} jam).");
                    }
                },
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
