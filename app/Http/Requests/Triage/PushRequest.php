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
            'tickets'                          => ['required', 'array'],
            'tickets.*.key'                    => ['required', 'string', 'max:50'],
            'tickets.*.summary'                => ['sometimes', 'nullable', 'string', 'max:500'],
            'tickets.*.status'                 => ['sometimes', 'nullable', 'string', 'max:100'],
            'tickets.*.assignee'               => ['sometimes', 'nullable', 'string', 'max:255'],
            'tickets.*.url'                    => ['sometimes', 'nullable', 'string', 'max:2048'],
            'tickets.*.flags'                  => ['sometimes', 'nullable', 'array'],
            'tickets.*.flags.*'                => ['string', 'max:50'],
            'tickets.*.attention_score'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tickets.*.last_updated'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'tickets.*.last_comment_at'        => ['sometimes', 'nullable', 'date'],
            'tickets.*.compliance_status'      => ['sometimes', 'nullable', 'string', 'max:20'],
            'tickets.*.compliance_coverage'    => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'git_branches'              => ['sometimes', 'nullable', 'array'],
            'git_branches.*.branch'     => ['required_with:git_branches', 'string'],
            'git_branches.*.base'       => ['sometimes', 'nullable', 'string'],
            'git_branches.*.tickets'    => ['sometimes', 'array'],
            'git_branches.*.tickets.*'  => ['string'],
            'git_branches.*.files'      => ['sometimes', 'array'],
            'git_branches.*.files.*'    => ['string'],
            'cli_activity'                       => ['sometimes', 'nullable', 'array'],
            'cli_activity.fetch_count'           => ['sometimes', 'integer', 'min:0', 'max:50000'],
            'cli_activity.triage_run_count'      => ['sometimes', 'integer', 'min:0', 'max:50000'],
            'cli_activity.invocations'           => ['sometimes', 'integer', 'min:0', 'max:50000'],
            'cli_activity.commands'                    => ['sometimes', 'nullable', 'array'],
            'cli_activity.commands.*'                  => ['array'],
            'cli_activity.commands.*.count'            => ['required_with:cli_activity.commands.*', 'integer', 'min:0', 'max:50000'],
            'cli_activity.commands.*.tokens_saved'     => ['sometimes', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
