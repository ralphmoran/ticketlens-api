<?php

namespace App\Http\Requests;

use App\Rules\ValidUtf8;
use Illuminate\Foundation\Http\FormRequest;

class EnterpriseContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // No control or line-separator characters in name: it lands in the mail subject line.
            'name'    => ['required', 'string', 'max:100', 'regex:/^[^\p{C}\p{Zl}\p{Zp}]+$/u', new ValidUtf8()],
            'email'   => ['required', 'string', 'max:254', 'email:rfc'],
            'message' => ['required', 'string', 'max:5000', new ValidUtf8()],
            // Honeypot: real visitors never see this field.
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function isSpam(): bool
    {
        return $this->filled('website');
    }
}
