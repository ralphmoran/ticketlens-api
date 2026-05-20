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
            'profile'                   => ['required', 'string', 'max:100'],
            'captured_at'               => ['required', 'date'],
            'tickets'                   => ['required', 'array'],
            'tickets.*.key'             => ['required', 'string'],
            'git_branches'              => ['sometimes', 'nullable', 'array'],
            'git_branches.*.branch'     => ['required_with:git_branches', 'string'],
            'git_branches.*.base'       => ['sometimes', 'nullable', 'string'],
            'git_branches.*.tickets'    => ['sometimes', 'array'],
            'git_branches.*.tickets.*'  => ['string'],
            'git_branches.*.files'      => ['sometimes', 'array'],
            'git_branches.*.files.*'    => ['string'],
        ];
    }
}
