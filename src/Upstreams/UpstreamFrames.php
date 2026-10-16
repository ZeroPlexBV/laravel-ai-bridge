<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Upstreams;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Events\UpstreamAuthCompleted;
use Tetrix\AiBridge\Events\UpstreamAuthRequired;
use Tetrix\AiBridge\Events\UpstreamStatusChanged;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/**
 * The serve process's handling of the bridge frames about upstream MCP servers:
 * `mcp_result`, `upstream_status`, `upstream_auth_required` and `upstream_auth_result`.
 *
 * Runs inside the ReactPHP event loop, where an uncaught TypeError drops every connected
 * bridge, so every field is type-checked and a malformed frame is logged and dropped. Each
 * method returns null: nothing goes back to the bridge; answers go to whoever is waiting
 * (see BridgeConnectionManager::registerPendingExchange()).
 */
final class UpstreamFrames
{
    /** The codes an `mcp_result` error may carry; anything else becomes `upstream_error`. */
    public const MCP_CODES = ['unknown_server', 'unavailable', 'timeout', 'refused', 'unsupported', 'upstream_error'];

    /** The codes an `upstream_auth_result` failure may carry; anything else becomes `failed`. */
    public const AUTH_CODES = ['unknown_server', 'not_oauth', 'unknown_state', 'denied', 'no_redirect_uri', 'discovery_failed', 'registration_failed', 'exchange_failed', 'issuer_mismatch'];

    public const STATES = ['connecting', 'connected', 'needs_auth', 'unavailable'];

    public const AUTH_KINDS = ['none', 'secrets', 'oauth'];

    /** The most upstreams one status frame is read for. */
    private const MAX_UPSTREAMS = 100;

    public function __construct(
        private readonly BridgeConnectionManager $connections,
    ) {}

    /** @param  array<string, mixed>  $message */
    public function mcpResult(string $connectionId, array $message): ?array
    {
        $userId = $this->senderOf($connectionId, 'mcp_result');
        $id = $message['id'] ?? null;

        if ($userId === null || ! is_string($id) || $id === '' || strlen($id) > 128) {
            return null;
        }

        if (array_key_exists('result', $message) && is_array($message['result'])) {
            $answer = ['ok' => true, 'result' => $message['result']];
        } else {
            $code = $message['code'] ?? null;
            $error = $message['error'] ?? null;
            $answer = [
                'ok' => false,
                'code' => is_string($code) && in_array($code, self::MCP_CODES, true) ? $code : 'upstream_error',
                'error' => is_string($error) && $error !== '' ? mb_substr($error, 0, 1000) : 'The upstream request failed.',
            ];
        }

        $this->connections->resolvePendingExchange('mcp:'.$id, $userId, $answer);

        return null;
    }

    /** @param  array<string, mixed>  $message */
    public function status(string $connectionId, array $message): ?array
    {
        $userId = $this->senderOf($connectionId, 'upstream_status');
        if ($userId === null) {
            return null;
        }

        $upstreams = self::upstreamsOf($message['upstreams'] ?? null);

        if ($this->connections->setUpstreams($userId, $upstreams)) {
            try {
                UpstreamStatusChanged::dispatch($userId, $this->connections->getConnectionRowId($userId), $upstreams);
            } catch (\Throwable $e) {
                Log::warning('AI Bridge: an UpstreamStatusChanged listener failed', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $message */
    public function authRequired(string $connectionId, array $message): ?array
    {
        $userId = $this->senderOf($connectionId, 'upstream_auth_required');
        if ($userId === null) {
            return null;
        }

        $server = self::serverOf($message['server'] ?? null);
        $state = $message['state'] ?? null;
        $url = $message['authorization_url'] ?? null;
        $expiresAt = $message['expires_at'] ?? null;
        $reason = $message['reason'] ?? null;

        $scheme = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_SCHEME)) : '';
        $at = is_string($expiresAt) ? strtotime($expiresAt) : false;

        if ($server === null
            || ! is_string($state) || strlen($state) < 16 || strlen($state) > 512
            || ! is_string($url) || strlen($url) > 8192 || ! in_array($scheme, ['https', 'http'], true)
            || $at === false || $at <= time()) {
            Log::warning('AI Bridge: malformed or expired upstream_auth_required dropped', [
                'connection_id' => $connectionId,
                'server' => is_string($message['server'] ?? null) ? mb_substr($message['server'], 0, 128) : null,
            ]);

            return null;
        }

        $record = [
            'state' => $state,
            'connection_key' => $userId,
            'connection_id' => $this->connections->getConnectionRowId($userId),
            'server' => $server,
            'authorization_url' => $url,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', min($at, time() + PendingSignIns::MAX_TTL)),
            'reason' => is_string($reason) && preg_match('/^[a-z_]{1,32}$/', $reason) === 1 ? $reason : 'no_token',
        ];

        try {
            app(PendingSignIns::class)->put($record);
        } catch (\Throwable $e) {
            Log::error('AI Bridge: could not keep a pending upstream sign-in', [
                'server' => $server,
                'error' => $e->getMessage(),
            ]);
        }

        $this->connections->resolvePendingExchange('auth-start:'.$userId.':'.$server, $userId, [
            'ok' => true,
            'server' => $server,
            'authorization_url' => $record['authorization_url'],
            'expires_at' => $record['expires_at'],
            'reason' => $record['reason'],
        ]);

        try {
            UpstreamAuthRequired::dispatch($userId, $record['connection_id'], $server, $record['authorization_url'], $record['expires_at'], $record['reason']);
        } catch (\Throwable $e) {
            Log::warning('AI Bridge: an UpstreamAuthRequired listener failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /** @param  array<string, mixed>  $message */
    public function authResult(string $connectionId, array $message): ?array
    {
        $userId = $this->senderOf($connectionId, 'upstream_auth_result');
        $server = self::serverOf($message['server'] ?? null);

        if ($userId === null || $server === null) {
            return null;
        }

        $state = $message['state'] ?? null;
        $ok = ($message['ok'] ?? null) === true;
        $answer = ['ok' => $ok, 'server' => $server];

        if (! $ok) {
            $code = $message['code'] ?? null;
            $error = $message['error'] ?? null;
            $answer['code'] = is_string($code) && in_array($code, self::AUTH_CODES, true) ? $code : 'failed';
            $answer['error'] = is_string($error) && $error !== '' ? mb_substr($error, 0, 500) : null;
        }

        if ($ok) {
            try {
                app(PendingSignIns::class)->forgetServer($userId, $server);
            } catch (\Throwable) {
                // It expires on its own.
            }
        }

        if (is_string($state) && $state !== '' && strlen($state) <= 512) {
            $this->connections->resolvePendingExchange('auth:'.$userId.':'.$state, $userId, $answer);

            return null;
        }

        // No state: a start that failed, or a sign-in the bridge could not prepare by itself
        // (discovery or registration failing at the first prompt after connecting), which
        // nobody is waiting for. Either way a sign-in failed, so it is announced here; with a
        // state the callback announces it, with the signed-in person.
        $this->connections->resolvePendingExchange('auth-start:'.$userId.':'.$server, $userId, $answer);

        if (! $ok) {
            try {
                UpstreamAuthCompleted::dispatch($this->connections->getConnectionRowId($userId), $userId, null, $server, false, $answer['code'], $answer['error']);
            } catch (\Throwable $e) {
                Log::warning('AI Bridge: an UpstreamAuthCompleted listener failed', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }

    /**
     * The upstreams of a status frame, each reduced to the documented fields and values.
     *
     * @return list<array{server: string, transport: string|null, origin: string|null, auth: string|null, state: string, tools: int, error: string|null}>
     */
    public static function upstreamsOf(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? array_slice($value, 0, self::MAX_UPSTREAMS) : [] as $item) {
            $server = is_array($item) ? self::serverOf($item['server'] ?? null) : null;
            if ($server === null) {
                continue;
            }

            $transport = in_array($item['transport'] ?? null, ['http', 'stdio'], true) ? $item['transport'] : null;
            $error = $item['error'] ?? null;

            $out[] = [
                'server' => $server,
                'transport' => $transport,
                'origin' => $transport === 'stdio' ? null : self::originOf($item['origin'] ?? null),
                'auth' => in_array($item['auth'] ?? null, self::AUTH_KINDS, true) ? $item['auth'] : null,
                'state' => in_array($item['state'] ?? null, self::STATES, true) ? $item['state'] : 'unavailable',
                'tools' => is_int($item['tools'] ?? null) && $item['tools'] >= 0 ? $item['tools'] : 0,
                'error' => is_string($error) && $error !== '' ? mb_substr($error, 0, 300) : null,
            ];
        }

        return $out;
    }

    /** An upstream's name: a non-empty string of at most 128 characters, or null. */
    public static function serverOf(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 128 ? $value : null;
    }

    /** Scheme, host and port only, whatever else arrived; null when it is not an http(s) URL. */
    private static function originOf(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 2048) {
            return null;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $parts['host'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return null;
        }

        return $scheme.'://'.strtolower($host).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function senderOf(string $connectionId, string $frame): ?string
    {
        $userId = $this->connections->getUserIdByConnectionId($connectionId);

        if ($userId === null) {
            Log::warning("AI Bridge: {$frame} from unauthenticated connection", ['connection_id' => $connectionId]);
        }

        return $userId;
    }
}
