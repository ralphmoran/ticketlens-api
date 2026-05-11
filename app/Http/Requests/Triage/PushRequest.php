<?php

namespace App\Http\Requests\Triage;

use Illuminate\Foundation\Http\FormRequest;

class PushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profile'       => ['required', 'string', 'max:100'],
            'captured_at'   => ['required', 'date'],
            'tickets'       => ['required', 'array'],
            'tickets.*.key' => ['required', 'string'],
        ];
    }
}
