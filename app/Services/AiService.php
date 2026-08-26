<?php

namespace App\Services;

use App\Exceptions\NoAiProviderException;
use App\Models\User;
use App\Models\UserAiProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AiService
{
    private const DEFAULT_MAX_TOKENS = 256;

    public function summarize(User $user, string $brief): string
    {
        $sanitized = mb_substr(str_replace("\x00", '', $brief), 0, 50_000);

        return $this->generateText($user, $this->buildPrompt($sanitized));
    }

    /**
     * Generic entry point behind summarize() — same enabled/priority-ordered
     * UserAiProvider fallback chain, but with a caller-supplied prompt instead
     * of the hardcoded "Summarize this Jira ticket..." one. Added so other
     * features (AI-provider-role prompt generation) can reuse the exact same
     * reliability behavior without duplicating the fallback loop.
     *
     * $maxTokens matters more than it looks: reasoning models (e.g. Groq's
     * openai/gpt-oss-120b, the post-2026-08-16 default after llama-3.3-70b's
     * deprecation) spend tokens on a hidden reasoning trace before the visible
     * answer, counted against this same budget — a too-small value can return
     * a 200 with empty content (finish_reason: "length", confirmed live) even
     * though the call "succeeded". Real generative work needs real headroom;
     * summarize()'s short 3-sentence task keeps the original 256 default.
     */
    public function generateText(User $user, string $prompt, int $maxTokens = self::DEFAULT_MAX_TOKENS): string
    {
        $providers = $user->aiProviders()->where('enabled', true)->get();

        if ($providers->isEmpty()) {
            throw new NoAiProviderException();
        }

        $errors = [];
        foreach ($providers as $provider) {
            try {
                return $this->callProvider($provider, $prompt, $maxTokens);
            } catch (\Throwable $e) {
                $errors[] = "{$provider->provider} ({$e->getMessage()})";
            }
        }

        throw new \RuntimeException('AI unavailable. Tried: ' . implode(', ', $errors));
    }

    private function callProvider(UserAiProvider $provider, string $prompt, int $maxTokens): string
    {
        return match ($provider->provider) {
            'anthropic' => $this->callAnthropic($provider, $prompt),
            'groq'      => $this->callOpenAiCompat($provider, $prompt, config('services.groq.url'), config('services.groq.model'), $maxTokens),
            'openai'    => $this->callOpenAiCompat($provider, $prompt, 'https://api.openai.com/v1/chat/completions', 'gpt-4o-mini', $maxTokens),
            default     => throw new \InvalidArgumentException("Unknown provider: {$provider->provider}"),
        };
    }

    private function callAnthropic(UserAiProvider $provider, string $prompt): string
    {
        $response = Http::timeout($provider->timeout_seconds)
            ->withHeaders([
                'x-api-key'         => $provider->api_key,
                'anthropic-version' => config('services.anthropic.version'),
            ])
            ->post(config('services.anthropic.url'), [
                'model'      => config('services.anthropic.model'),
                'max_tokens' => config('services.anthropic.max_tokens'),
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]);

        return $this->successful($response)->json('content.0.text');
    }

    private function callOpenAiCompat(UserAiProvider $provider, string $prompt, string $url, string $model, int $maxTokens): string
    {
        $response = Http::timeout($provider->timeout_seconds)
            ->withToken($provider->api_key)
            ->post($url, [
                'model'      => $model,
                'max_tokens' => $maxTokens,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
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

    /** Used only by the /test endpoint — fixed minimal prompt, never user data. */
    public function testProvider(UserAiProvider $provider): string
    {
        return $this->callProvider($provider, 'Say OK in exactly one word.', self::DEFAULT_MAX_TOKENS);
    }

    private function buildPrompt(string $brief): string
    {
        return "Summarize this Jira ticket in 3 sentences. Focus on what matters most for implementation. Be concrete.\n\n{$brief}";
    }
}
