<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class SlackService
{
    private const AUTH_URL     = 'https://slack.com/oauth/v2/authorize';
    private const TOKEN_URL    = 'https://slack.com/api/oauth.v2.access';
    private const CHANNELS_URL = 'https://slack.com/api/conversations.list';
    private const MEMBERS_URL  = 'https://slack.com/api/users.list';
    private const OPEN_DM_URL  = 'https://slack.com/api/conversations.open';
    private const POST_URL     = 'https://slack.com/api/chat.postMessage';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {}

    /**
     * Build the Slack OAuth redirect URL.
     * State encodes group_id + user_id + is_owner + CSRF nonce so the callback
     * works without a session (cross-domain from ngrok / production redirect).
     */
    public function buildAuthUrl(
        int    $groupId,
        int    $userId,
        bool   $isOwner = false,
        bool   $popup = false,
        string $popupOrigin = '',
    ): string {
        $state = encrypt(json_encode([
            'group_id'     => $groupId,
            'user_id'      => $userId,
            'is_owner'     => $isOwner,
            'popup'        => $popup,
            'popup_origin' => $popupOrigin,
            'nonce'        => Str::random(32),
        ]));

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'    => $this->clientId,
            'scope'        => 'channels:read,groups:read,chat:write,users:read,im:write',
            'redirect_uri' => $this->redirectUri,
            'state'        => $state,
        ]);
    }

    /**
     * Decode and validate the OAuth state parameter.
     *
     * @return array{group_id: int, nonce: string}
     * @throws \RuntimeException on tampered or malformed state
     */
    public function decodeState(string $state): array
    {
        try {
            $payload = json_decode(decrypt($state), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Invalid OAuth state.', previous: $e);
        }

        if (! isset($payload['group_id'], $payload['user_id'], $payload['nonce'])) {
            throw new \RuntimeException('Malformed OAuth state payload.');
        }

        return $payload;
    }

    /**
     * Exchange the Slack authorization code for a bot token.
     *
     * @return array{workspace_id: string, workspace_name: string, bot_token: string}
     * @throws \RuntimeException on Slack API error
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::timeout(10)->asForm()->post(self::TOKEN_URL, [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
        ]);

        $body = $response->json();

        if (! ($body['ok'] ?? false)) {
            throw new \RuntimeException('Slack token exchange failed: ' . ($body['error'] ?? 'unknown'));
        }

        return [
            'workspace_id'   => $body['team']['id'],
            'workspace_name' => $body['team']['name'],
            'bot_token'      => $body['access_token'],
        ];
    }

    /**
     * Fetch public + private channels the bot can see (follows cursor pagination).
     *
     * @return list<array{id: string, name: string, is_private: bool}>
     * @throws \RuntimeException on Slack API error
     */
    public function fetchChannels(string $botToken): array
    {
        $raw = $this->paginatedGet($botToken, self::CHANNELS_URL, [
            'types'            => 'public_channel,private_channel',
            'exclude_archived' => true,
            'limit'            => 200,
        ], 'channels');

        return array_map(fn ($ch) => [
            'id'         => $ch['id'],
            'name'       => $ch['name'],
            'is_private' => $ch['is_private'] ?? false,
        ], $raw);
    }

    /**
     * Fetch active, non-bot workspace members (follows cursor pagination).
     *
     * @return list<array{id: string, name: string, real_name: string, avatar: string|null}>
     * @throws \RuntimeException on Slack API error
     */
    public function fetchMembers(string $botToken): array
    {
        $raw = $this->paginatedGet($botToken, self::MEMBERS_URL, ['limit' => 200], 'members');

        $raw = array_filter(
            $raw,
            fn ($m) => ! ($m['is_bot'] ?? false)
                    && ! ($m['deleted'] ?? false)
                    && $m['id'] !== 'USLACKBOT'
        );

        return array_values(array_map(fn ($m) => [
            'id'        => $m['id'],
            'name'      => ($m['profile']['display_name'] ?? '') ?: ($m['profile']['real_name'] ?? $m['name'] ?? ''),
            'real_name' => $m['profile']['real_name'] ?? '',
            'avatar'    => $m['profile']['image_48'] ?? null,
        ], $raw));
    }

    /**
     * Follow Slack's cursor-based pagination and accumulate all items from $key.
     *
     * @throws \RuntimeException on Slack API error
     */
    private function paginatedGet(string $token, string $url, array $params, string $key): array
    {
        $results = [];
        $cursor  = null;

        do {
            if ($cursor) {
                $params['cursor'] = $cursor;
            }

            $body = Http::timeout(10)->withToken($token)->get($url, $params)->json();

            if (! ($body['ok'] ?? false)) {
                throw new \RuntimeException('Slack API error: ' . ($body['error'] ?? 'unknown'));
            }

            foreach ($body[$key] ?? [] as $item) {
                $results[] = $item;
            }

            $cursor = $body['response_metadata']['next_cursor'] ?? null;
        } while ($cursor);

        return $results;
    }

    /**
     * Post a message to a channel. Used by alert features (37-40).
     *
     * @throws \RuntimeException on Slack API error
     */
    public function postMessage(string $botToken, string $channelId, string $text): void
    {
        $response = Http::timeout(10)->withToken($botToken)->post(self::POST_URL, [
            'channel' => $channelId,
            'text'    => $text,
        ]);

        $body = $response->json();

        if (! ($body['ok'] ?? false)) {
            throw new \RuntimeException('Slack postMessage failed: ' . ($body['error'] ?? 'unknown'));
        }
    }

    /**
     * Open a DM channel with a user and post a message.
     *
     * @throws \RuntimeException on Slack API error
     */
    public function postDm(string $botToken, string $userId, string $text): void
    {
        $response = Http::timeout(10)->withToken($botToken)->post(self::OPEN_DM_URL, ['users' => $userId]);
        $body     = $response->json();

        if (! ($body['ok'] ?? false)) {
            throw new \RuntimeException('Slack openDm failed: ' . ($body['error'] ?? 'unknown'));
        }

        $this->postMessage($botToken, $body['channel']['id'], $text);
    }
}
