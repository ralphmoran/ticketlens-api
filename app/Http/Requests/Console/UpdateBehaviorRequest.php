<?php

namespace App\Http\Requests\Console;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBehaviorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idle_warning_minutes'  => ['nullable', 'integer', Rule::in(User::IDLE_WARNING_CHOICES)],
            'session_message_style' => ['required', 'string', Rule::in(User::SESSION_MESSAGE_STYLES)],
        ];
    }
}
