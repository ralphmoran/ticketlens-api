<?php

namespace App\Services;

use App\Models\AiProviderPool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Calling layer for the dynamic AiProviderPool registry — distinct from AiService,
 * which stays untouched and keeps serving the legacy hardcoded groq/anthropic/openai
 * UserAiProvider path (summarize/handoff). Pool rows carry their own endpoint/model,
 * so this never reads config() the way AiService does for its 3 fixed providers.
 */
class AiProviderPoolService
{
    private const TIMEOUT_SECONDS = 30;

    public function call(AiProviderPool $pool, string $prompt, int $maxTokens): string
    {
        return match ($pool->provider_type) {
            'anthropic'         => $this->callAnthropic($pool, $prompt, $maxTokens),
            'openai_compatible' => $this->callOpenAiCompatible($pool, $prompt, $maxTokens),
            default             => throw new \InvalidArgumentException("Unknown provider_type: {$pool->provider_type}"),
        };
    }

    private function callAnthropic(AiProviderPool $pool, string $prompt, int $maxTokens): string
    {
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders([
                'x-api-key'         => $pool->api_key,
                'anthropic-version' => config('services.anthropic.version'),
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => $pool->model,
                'max_tokens' => $maxTokens,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]);

        return $this->successful($response)->json('content.0.text');
    }

    private function callOpenAiCompatible(AiProviderPool $pool, string $prompt, int $maxTokens): string
    {
        if (! $pool->endpoint) {
            throw new \RuntimeException("Pool provider \"{$pool->title}\" has no endpoint configured.");
        }

        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withToken($pool->api_key)
            ->post($pool->endpoint, [
                'model'      => $pool->model,
                'max_tokens' => $maxTokens,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]);

        return $this->successful($response)->json('choices.0.message.content');
    }

    private function successful(Response $response): Response
    {
        if (! $response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()}");
        }

        return $response;
    }
}
