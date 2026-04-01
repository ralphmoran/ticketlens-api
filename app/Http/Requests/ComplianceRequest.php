<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ComplianceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'ticketKey' => ['nullable', 'string', 'regex:/^[A-Z]+-\d+$/', 'max:50'],
            'brief'     => ['required', 'string', 'max:50000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('brief')) {
            $this->merge(['brief' => str_replace("\x00", '', $this->input('brief'))]);
        }
    }
}
