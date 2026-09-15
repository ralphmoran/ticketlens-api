<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecallAutoCaptureRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'transcript_excerpt' => ['required', 'string', 'max:8000'],
            'ticket_key' => ['sometimes', 'string', 'regex:/^[A-Z][A-Z0-9]+-\d+$/', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Strip null bytes before validation
        if ($this->has('transcript_excerpt')) {
            $this->merge(['transcript_excerpt' => str_replace("\x00", '', $this->input('transcript_excerpt'))]);
        }
    }
}
