<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SummarizeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'ticketKey' => ['nullable', 'string', 'max:50'],
            'brief' => ['required', 'string', 'max:50000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Strip null bytes before validation
        if ($this->has('brief')) {
            $this->merge(['brief' => str_replace("\x00", '', $this->input('brief'))]);
        }
    }
}
