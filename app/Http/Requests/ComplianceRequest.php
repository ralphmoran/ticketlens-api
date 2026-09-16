<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ComplianceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'ticketKey' => ['nullable', 'string', 'regex:/^[A-Z][A-Z0-9]+-\d+$/', 'max:50'],
            'brief'     => ['required', 'string', 'max:50000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('brief')) {
            $this->merge(['brief' => str_replace("\x00", '', $this->input('brief'))]);
        }
        // Jira/Linear project and team keys are always created uppercase, so a
        // lowercase ticketKey is always a typo, never a distinct real key —
        // mirrors the CLI's own normalizeTicketKey (skills/jtb/scripts/lib/cli.mjs).
        if ($this->has('ticketKey') && is_string($this->input('ticketKey'))) {
            $this->merge(['ticketKey' => strtoupper($this->input('ticketKey'))]);
        }
    }
}
