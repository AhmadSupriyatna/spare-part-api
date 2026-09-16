<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class RescheduleTaskRequest extends FormRequest
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
            'due_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * A shift of more than 7 days from the task's current due date needs a
     * reason — checked here (not a static rule) since it depends on the
     * route-bound Task's current value.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $task = $this->route('task');

            if (! $task || ! $task->due_date || ! $this->filled('due_date')) {
                return;
            }

            $diffDays = $task->due_date->diffInDays(Carbon::parse($this->input('due_date')));

            if ($diffDays > 7 && ! $this->filled('reason')) {
                $validator->errors()->add(
                    'reason',
                    'Pergeseran jadwal lebih dari 7 hari wajib disertai alasan.'
                );
            }
        });
    }
}
