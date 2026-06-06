<?php

namespace App\Services;

use App\Exceptions\NoAiProviderException;
use App\Models\User;
use App\Models\UserAiProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AiService
{
    public function summarize(User $user, string $brief): string
    {
        $sanitized = mb_substr(str_replace("\x00", '', $brief), 0, 50_000);

        $providers = $user->aiProviders()->where('enabled', true)->get();

        if ($providers->isEmpty()) {
            throw new NoAiProviderException();
        }

        $errors = [];
        foreach ($providers as $provider) {
            try {
                return $this->callProvider($provider, $sanitized);
            } catch (\Throwable $e) {
                $errors[] = "{$provider->provider} ({$e->getMessage()})";
            }
        }

        throw new \RuntimeException('AI unavailable. Tried: ' . implode(', ', $errors));
    }

    private function callProvider(UserAiProvider $provider, string $brief): string
    {
        return match ($provider->provider) {
            'anthropic' => $this->callAnthropic($provider, $brief),
            'groq'      => $this->callOpenAiCompat($provider, $brief, config('services.groq.url'), config('services.groq.model')),
            'openai'    => $this->callOpenAiCompat($provider, $brief, 'https://api.openai.com/v1/chat/completions', 'gpt-4o-mini'),
            default     => throw new \InvalidArgumentException("Unknown provider: {$provider->provider}"),
        };
    }

    private function callAnthropic(UserAiProvider $provider, string $brief): string
    {
        $response = Http::timeout($provider->timeout_seconds)
            ->withHeaders([
                'x-api-key'         => $provider->api_key,
                'anthropic-version' => config('services.anthropic.version'),
            ])
            ->post(config('services.anthropic.url'), [
                'model'      => config('services.anthropic.model'),
                'max_tokens' => config('services.anthropic.max_tokens'),
                'messages'   => [$this->userMessage($brief)],
            ]);

        return $this->successful($response)->json('content.0.text');
    }

    private function callOpenAiCompat(UserAiProvider $provider, string $brief, string $url, string $model): string
    {
        $response = Http::timeout($provider->timeout_seconds)
            ->withToken($provider->api_key)
            ->post($url, [
                'model'      => $model,
                'max_tokens' => 256,
                'messages'   => [$this->userMessage($brief)],
            ]);

        return $this->successful($response)->json('choices.0.message.content');
    }

    /** Returns the response if it succeeded, otherwise throws with the HTTP status. */
    private function successful(Response $response): Response
    {
        if (! $response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()}");
        }

        return $response;
    }

    private function userMessage(string $brief): array
    {
        return ['role' => 'user', 'content' => $this->buildPrompt($brief)];
    }

    /** Used only by the /test endpoint — fixed minimal prompt, never user data. */
    public function testProvider(UserAiProvider $provider): string
    {
        return $this->callProvider($provider, 'Say OK in exactly one word.');
    }

    private function buildPrompt(string $brief): string
    {
        return "Summarize this Jira ticket in 3 sentences. Focus on what matters most for implementation. Be concrete.\n\n{$brief}";
    }
}
