<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Upstreams;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Connections\ConnectionStatus;
use Tetrix\AiBridge\Events\McpRequestRelayed;
use Tetrix\AiBridge\Models\Connection;

/**
 * The PHP API for a bridge's upstream MCP servers: their status, OAuth sign-in, and relaying
 * a view's requests (`mcp_request`). Reached as `AiBridge::upstreams()`.
 *
 * Works from any process except the serve process itself: like sendTurnInput() and
 * usage(), every call reaches the serve process over its internal HTTP API with a relay
 * token for the connection, and the serve process holds the request open while the bridge
 * answers. Takes a Connection the caller has already fetched through its own scope
 * (AiBridge::connectionsQuery()); authorising the lookup is the caller's job.
 */
class UpstreamClient
{
    /** The only methods a view's request may use. */
    public const METHODS = ['resources/read', 'tools/call'];

    public function __construct(
        private readonly TokenManager $tokenManager,
        private readonly ConnectionStatus $status,
        private readonly PendingSignIns $signIns,
    ) {}

    /**
     * The upstreams of a connection's bridge and the sign-ins waiting on the person.
     *
     * `upstreams` is the last `upstream_status` (kept while the machine is off; check
     * `connected`). `mcp_upstream_auth` is whether the bridge can sign in to OAuth upstreams.
     *
     * @return array{connected: bool, mcp_apps: bool, mcp_upstream_auth: bool, upstreams: list<array<string, mixed>>, pending: list<array<string, mixed>>}
     */
    public function status(Connection $connection): array
    {
        $status = $this->status->for($connection);

        return [
            'connected' => $status['connected'],
            'mcp_apps' => $status['mcp_apps'],
            'mcp_upstream_auth' => $status['mcp_upstream_auth'],
            'upstreams' => $status['upstream_status'],
            'pending' => $this->pending($connection),
        ];
    }

    /**
     * The sign-ins waiting on the person, one per upstream at most:
     * `{connection_id, server, authorization_url, expires_at, reason}`.
     *
     * @return list<array{connection_id: int, server: string, authorization_url: string, expires_at: string, reason: string}>
     */
    public function pending(Connection $connection): array
    {
        if (! $connection->isBridge() || ($connection->connection_key ?? '') === '') {
            return [];
        }

        return array_values(array_map(fn (array $r): array => [
            'connection_id' => $connection->id,
            'server' => (string) $r['server'],
            'authorization_url' => (string) $r['authorization_url'],
            'expires_at' => (string) $r['expires_at'],
            'reason' => (string) $r['reason'],
        ], $this->signIns->forConnectionKey($connection->connection_key)));
    }

    /**
     * Ask the bridge to start a sign-in to one upstream (`upstream_auth_start`).
     *
     * @return array{ok: bool, server: string, authorization_url?: string, expires_at?: string, reason?: string, code?: string, error?: string|null}
     *                 On `ok`, open `authorization_url` in the person's browser. `code` on
     *                 failure: the bridge's (`unknown_server`, `not_oauth`, `discovery_failed`,
     *                 `registration_failed`, `no_redirect_uri`, ...) or `not_connected`,
     *                 `mcp_upstream_auth_unsupported`, `no_answer`, `unreachable`.
     */
    public function startSignIn(Connection $connection, string $server): array
    {
        $timeout = (int) config('ai-bridge.upstream_oauth.start_timeout', 20) + 3;
        $answer = $this->relay($connection, '/api/upstream/auth-start', ['server' => $server], $timeout);

        return ['server' => $server] + $answer;
    }

    /**
     * Have the bridge forget an upstream's tokens (`upstream_auth_forget`). The upstream goes
     * to `needs_auth`; nothing asks the person to sign in again until startSignIn().
     *
     * @return bool  Whether the frame reached the bridge.
     */
    public function forget(Connection $connection, string $server): bool
    {
        if ($connection->isBridge() && ($connection->connection_key ?? '') !== '') {
            $this->signIns->forgetServer($connection->connection_key, $server);
        }

        return ($this->relay($connection, '/api/upstream/auth-forget', ['server' => $server])['sent'] ?? false) === true;
    }

    /**
     * Pass the authorization server's redirect for a pending sign-in to its bridge, and
     * return how it ended. The caller has already checked the sign-in is the person's and
     * claimed it (PendingSignIns::claim()), so a state is used once.
     *
     * @param  array<string, mixed>  $signIn  A PendingSignIns record.
     * @param  array<string, mixed>  $query  The redirect's query parameters.
     * @return array{ok: bool, code?: string, error?: string|null}
     */
    public function completeSignIn(array $signIn, array $query): array
    {
        $body = ['server' => $signIn['server'], 'state' => $signIn['state']];
        foreach (['code', 'iss', 'error', 'error_description'] as $field) {
            if (is_string($query[$field] ?? null) && $query[$field] !== '') {
                $body[$field] = $query[$field];
            }
        }

        $timeout = (int) config('ai-bridge.upstream_oauth.callback_timeout', 15) + 3;

        return $this->relayAs((string) $signIn['connection_key'], '/api/upstream/auth-code', $body, $timeout);
    }

    /**
     * Relay a view's request to an upstream MCP server through the connection's bridge
     * (`mcp_request`) and return the upstream's result, unchanged.
     *
     * Only `resources/read` with a `ui://` uri and `tools/call` are relayed; anything else is
     * refused here without reaching the bridge. A bridge whose hello had no `mcp_apps` is
     * refused too. Fires McpRequestRelayed whatever the outcome.
     *
     * Pass `$toolCallId` (the `tool_call_id` of the result whose view is asking) whenever the
     * request comes from a view, as a host should: the request is then held to that view, and
     * refused (`refused`) unless `$server` is the server its recorded `ui` names on this
     * connection, or (`unknown_view`) when no such view was recorded here.
     *
     * @param  array<string, mixed>  $params  The MCP params of the method, unchanged.
     * @param  int|null  $timeout  Seconds to wait; default ai-bridge.server.mcp_request_timeout.
     * @param  string|null  $toolCallId  The call whose view makes the request.
     * @return array<string, mixed>  The MCP result (`contents` for resources/read, a CallToolResult for tools/call).
     *
     * @throws McpRequestFailed  With the bridge's code, or one decided here.
     */
    public function mcpRequest(Connection $connection, string $server, string $method, array $params = [], ?int $timeout = null, ?string $toolCallId = null): array
    {
        $started = microtime(true);
        $target = $method === 'tools/call'
            ? (is_string($params['name'] ?? null) ? $params['name'] : null)
            : (is_string($params['uri'] ?? null) ? $params['uri'] : null);
        $outcome = 'ok';

        try {
            $refusal = self::refusalFor(UpstreamFrames::serverOf($server), $method, $params);
            if ($refusal !== null) {
                throw new McpRequestFailed($refusal['code'], $refusal['error']);
            }

            if ($toolCallId !== null) {
                $viewServer = UpstreamViews::serverOf((int) $connection->id, $toolCallId);
                if ($viewServer === null) {
                    throw new McpRequestFailed('unknown_view', 'No upstream view was recorded for this call on this connection.');
                }
                if ($viewServer !== $server) {
                    throw new McpRequestFailed('refused', 'This view belongs to another upstream server.');
                }
            }

            $wait = $timeout ?? (int) config('ai-bridge.server.mcp_request_timeout', 65);
            $answer = $this->relay($connection, '/api/mcp', [
                'server' => $server,
                'method' => $method,
                'params' => (object) $params,
            ], $wait + 3);

            if (($answer['ok'] ?? false) === true && is_array($answer['result'] ?? null)) {
                return $answer['result'];
            }

            throw new McpRequestFailed(
                is_string($answer['code'] ?? null) ? $answer['code'] : 'upstream_error',
                is_string($answer['error'] ?? null) && $answer['error'] !== '' ? $answer['error'] : 'The upstream request failed.',
            );
        } catch (McpRequestFailed $e) {
            $outcome = $e->errorCode;

            throw $e;
        } finally {
            try {
                McpRequestRelayed::dispatch(
                    self::authUserId(),
                    (int) $connection->id,
                    $server,
                    $method,
                    $target === null ? null : mb_substr($target, 0, 512),
                    $outcome,
                    (int) round((microtime(true) - $started) * 1000),
                );
            } catch (\Throwable $e) {
                Log::warning('AI Bridge: a McpRequestRelayed listener failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Why a view's request must not be relayed, or null when it may.
     *
     * The same check runs in the web process (so nothing is sent) and in the serve process
     * (so a relay token alone cannot widen it).
     *
     * @return array{code: string, error: string}|null
     */
    public static function refusalFor(?string $server, mixed $method, mixed $params): ?array
    {
        if ($server === null) {
            return ['code' => 'invalid_request', 'error' => 'An upstream server name is required.'];
        }

        if (! is_string($method) || ! in_array($method, self::METHODS, true)) {
            return ['code' => 'unsupported', 'error' => 'Only resources/read and tools/call are relayed to an upstream.'];
        }

        if (! is_array($params) || ($params !== [] && array_is_list($params))) {
            return ['code' => 'invalid_request', 'error' => 'params must be an object.'];
        }

        if ($method === 'resources/read') {
            $uri = $params['uri'] ?? null;
            if (! is_string($uri) || ! str_starts_with(strtolower($uri), 'ui://')) {
                return ['code' => 'refused', 'error' => 'A view may only read ui:// resources.'];
            }
        }

        if ($method === 'tools/call') {
            $name = $params['name'] ?? null;
            $arguments = $params['arguments'] ?? [];
            if (! is_string($name) || $name === '') {
                return ['code' => 'invalid_request', 'error' => 'tools/call needs a tool name.'];
            }
            if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
                return ['code' => 'invalid_request', 'error' => 'tools/call arguments must be an object.'];
            }
        }

        return null;
    }

    /**
     * POST to the serve process for a connection's bridge and return its JSON answer, with
     * failures turned into `{ok: false, code, error}`.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function relay(Connection $connection, string $path, array $body, int $timeout = 0): array
    {
        if (! $connection->isBridge() || ($connection->connection_key ?? '') === '') {
            return ['ok' => false, 'code' => 'invalid_request', 'error' => 'Only a CLI bridge connection has upstream MCP servers.'];
        }

        return $this->relayAs($connection->connection_key, $path, $body, $timeout);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function relayAs(string $connectionKey, string $path, array $body, int $timeout = 0): array
    {
        try {
            $relayToken = $this->tokenManager->generate($connectionKey, ['scope' => TokenManager::INTERNAL_RELAY_SCOPE], 60);

            /** @var Response $response */
            $response = Http::withToken($relayToken)
                ->timeout($timeout > 0 ? $timeout : (int) config('ai-bridge.server.relay_timeout', 5))
                ->acceptJson()
                ->post(self::internalApiBase().$path, $body);
        } catch (\Throwable $e) {
            Log::info('AI Bridge: could not reach the serve process for an upstream request', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'code' => 'unreachable', 'error' => 'The bridge server could not be reached.'];
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if ($response->successful()) {
            return $json;
        }

        return [
            'ok' => false,
            'code' => is_string($json['code'] ?? null) ? $json['code'] : 'unreachable',
            'error' => is_string($json['error'] ?? null) ? $json['error'] : 'The bridge server answered '.$response->status().'.',
        ] + (isset($json['sent']) ? ['sent' => $json['sent']] : []);
    }

    private static function authUserId(): int|string|null
    {
        try {
            return auth()->id();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function internalApiBase(): string
    {
        $relayUrl = config('ai-bridge.server.relay_url');
        if (! empty($relayUrl)) {
            return rtrim((string) $relayUrl, '/');
        }

        $host = (string) config('ai-bridge.server.host', '127.0.0.1');
        $port = (int) config('ai-bridge.server.port', 8085);
        if ($host === '0.0.0.0') {
            $host = '127.0.0.1';
        }

        return "http://{$host}:{$port}";
    }
}
