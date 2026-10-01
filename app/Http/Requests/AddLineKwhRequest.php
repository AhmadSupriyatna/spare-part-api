<?php

namespace App\Http\Requests;

use App\Models\CompanySetting;
use App\Models\LineKwhLog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class AddLineKwhRequest extends FormRequest
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
            // Any date inside the target week — the server normalizes it to
            // that week's Monday (see LineController::addKwh()).
            'for_date' => ['required', 'date'],
            // Meaning depends on CompanySetting::kwh_input_mode: the meter's
            // absolute reading in "reading" mode, or that week's usage
            // directly in "direct" mode.
            'value' => [
                'required',
                'integer',
                'min:0',
                function ($attribute, $value, $fail) {
                    if (CompanySetting::current()->kwh_input_mode !== 'reading') {
                        return;
                    }

                    $line = $this->route('line');
                    $weekStart = $this->input('for_date') ? Carbon::parse($this->input('for_date'))->startOfWeek(Carbon::MONDAY) : null;
                    if (! $line || ! $weekStart) {
                        return;
                    }

                    $previous = LineKwhLog::where('line_id', $line->id)
                        ->where('week_start_date', '<', $weekStart->toDateString())
                        ->orderByDesc('week_start_date')
                        ->value('new_reading');

                    if ($previous !== null && $value < $previous) {
                        $fail("Reading tidak boleh kurang dari reading minggu sebelumnya ({$previous} kWh).");
                    }
                },
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
