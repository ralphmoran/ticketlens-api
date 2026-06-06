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
            'tickets'                      => ['required', 'array'],
            'tickets.*.key'                => ['required', 'string'],
            'tickets.*.last_comment_at'    => ['sometimes', 'nullable', 'date'],
            'git_branches'              => ['sometimes', 'nullable', 'array'],
            'git_branches.*.branch'     => ['required_with:git_branches', 'string'],
            'git_branches.*.base'       => ['sometimes', 'nullable', 'string'],
            'git_branches.*.tickets'    => ['sometimes', 'array'],
            'git_branches.*.tickets.*'  => ['string'],
            'git_branches.*.files'      => ['sometimes', 'array'],
            'git_branches.*.files.*'    => ['string'],
            'cli_activity'                    => ['sometimes', 'nullable', 'array'],
            'cli_activity.fetch_count'        => ['sometimes', 'integer', 'min:0', 'max:50000'],
            'cli_activity.triage_run_count'   => ['sometimes', 'integer', 'min:0', 'max:50000'],
            'cli_activity.invocations'        => ['sometimes', 'integer', 'min:0', 'max:50000'],
        ];
    }
}
