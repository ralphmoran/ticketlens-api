<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DigestDeliverRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'profile'               => ['required', 'string', 'max:100'],
            'staleDays'             => ['required', 'integer', 'min:0', 'max:365'],
            'summary'               => ['required', 'array'],
            'summary.total'         => ['required', 'integer', 'min:0'],
            'summary.needsResponse' => ['required', 'integer', 'min:0'],
            'summary.aging'         => ['required', 'integer', 'min:0'],
            'tickets'               => ['required', 'array', 'min:1', 'max:50'],
            'tickets.*.ticketKey'   => ['required', 'string', 'max:50'],
            'tickets.*.summary'     => ['required', 'string', 'max:500'],
            'tickets.*.status'      => ['required', 'string', 'max:100'],
            'tickets.*.urgency'     => ['required', 'string', 'in:needs-response,aging,clear'],
        ];
    }
}
