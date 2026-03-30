<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class AnthropicService
{
    public function summarize(string $brief): string
    {
        // Security: strip null bytes only — preserve HTML/markdown for LLM context
        $sanitized = mb_substr(str_replace("\x00", '', $brief), 0, 50_000);

        $response = Http::timeout(30)
            ->withHeaders([
                'x-api-key' => config('services.anthropic.key'),
                'anthropic-version' => config('services.anthropic.version'),
            ])
            ->post(config('services.anthropic.url'), [
                'model' => config('services.anthropic.model'),
                'max_tokens' => config('services.anthropic.max_tokens'),
                'messages' => [[
                    'role' => 'user',
                    'content' => "Summarize this Jira ticket in 3 sentences. Focus on what matters most for implementation. Be concrete.\n\n{$sanitized}",
                ]],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("Anthropic API error: {$response->status()}");
        }

        return $response->json('content.0.text');
    }
}
