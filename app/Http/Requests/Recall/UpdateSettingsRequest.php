<?php

namespace App\Http\Requests\Recall;

use App\Models\RecallSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'flush_cooldown_ms' => ['required', 'integer', $this->between('flush_cooldown_ms')],
            'timeout_ms'        => ['required', 'integer', $this->between('timeout_ms')],
            'max_queue_size'    => ['required', 'integer', $this->between('max_queue_size')],
            'max_entry_age_ms'  => ['required', 'integer', $this->between('max_entry_age_ms')],
        ];
    }

    private function between(string $field): string
    {
        [$min, $max] = RecallSettings::BOUNDS[$field];

        return "between:{$min},{$max}";
    }
}
