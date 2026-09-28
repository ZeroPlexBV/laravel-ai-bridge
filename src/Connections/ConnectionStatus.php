<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Connections;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Models\Connection;

/**
 * Live status for an AI connection — whether it is currently usable and the providers it
 * offers — resolved authoritatively rather than from cached state.
 *
 * The bridge WebSocket server holds the real connection registry in memory (a PHP-FPM
 * worker can't see it), so for a **bridge** connection this queries the server's internal
 * `/api/status` endpoint (with a short-lived relay token) and write-through-refreshes the
 * connection's cached `last_providers` / `last_connected_at`. A **BYOK** connection is
 * always usable (it carries a key); its providers come from the chat-completions config.
 *
 * This is the public, reusable form of the logic the connections API uses internally —
 * call it from app code that needs to know if a connection is live (e.g. gating a feature
 * on an available provider) without re-deriving the relay-token + endpoint plumbing.
 */
class ConnectionStatus
{
    public function __construct(
        private readonly TokenManager $tokenManager,
    ) {}

    /**
     * Live status for any connection.
     *
     * `workspaces` is the directories a CLI bridge will work in, as the
     * operator allowed them with `--allow-dir`. Always empty for BYOK, which
     * has no machine to work on, and empty for a bridge whose operator allowed
     * none — which is also what an older bridge looks like, and means the same
     * thing: naming a working directory is refused.
     *
     * `posture` is the CLI isolation the bridge reported it actually adopted.
     * Empty for BYOK, and empty for a bridge that predates the report — which
     * means unknown, not agreement.
     *
     * `bridge_version` is the ai-bridge release the machine runs (`0.21.0`), from
     * its `hello`: compare it with the version the application pins to tell a
     * person their machine is behind. Kept from the last connection while the
     * machine is off; null for BYOK and for a machine never seen. The protocol
     * version is a different number and is not this.
     *
     * `attachment_limits` is the per-file / per-request caps the bridge enforces
     * (bytes; bridge 0.16+), or null. `capabilities` lists the optional frames
     * it announced: `turn_input`, `file_uploads`, `file_downloads`,
     * `app_backends`. Test a capability, not a version.
     *
     * @return array{connected: bool, providers: array<int, mixed>, workspaces: array<int, mixed>, posture: array<string, mixed>, bridge_version: string|null, attachment_limits: array<string, int>|null, capabilities: list<string>}
     */
    public function for(Connection $connection): array
    {
        if ($connection->isByok()) {
            return ['connected' => true, 'providers' => $this->byokProviders(), 'workspaces' => [], 'posture' => []] + self::bridgeFields([]);
        }

        return $this->bridgeLiveStatus($connection);
    }

    /**
     * The directories this connection's bridge will work in.
     *
     * @return array<int, mixed>
     */
    public function workspaces(Connection $connection): array
    {
        return $this->for($connection)['workspaces'];
    }

    /**
     * The CLI isolation posture this connection's bridge reported.
     *
     * A `reason` key means the bridge declined what the server asked for, and
     * the accompanying `message` names the operator flag that would change it.
     *
     * @return array<string, mixed>
     */
    public function posture(Connection $connection): array
    {
        return $this->for($connection)['posture'];
    }

    /** The ai-bridge release this connection's machine runs (or last ran), or null. */
    public function bridgeVersion(Connection $connection): ?string
    {
        return $this->for($connection)['bridge_version'];
    }

    /** Whether this connection's bridge announced a capability (see for()). */
    public function supports(Connection $connection, string $capability): bool
    {
        return in_array($capability, $this->for($connection)['capabilities'], true);
    }

    /**
     * The bridge-description fields of for(), from a stored or reported `bridge` array.
     *
     * @param  mixed  $bridge
     * @return array{bridge_version: string|null, attachment_limits: array<string, int>|null, capabilities: list<string>}
     */
    private static function bridgeFields(mixed $bridge): array
    {
        $bridge = is_array($bridge) ? $bridge : [];
        $version = $bridge['bridge_version'] ?? null;
        $limits = $bridge['attachment_limits'] ?? null;
        $capabilities = $bridge['capabilities'] ?? [];

        return [
            'bridge_version' => is_string($version) && $version !== '' ? $version : null,
            'attachment_limits' => is_array($limits) && $limits !== [] ? $limits : null,
            'capabilities' => is_array($capabilities) ? array_values(array_filter($capabilities, 'is_string')) : [],
        ];
    }

    /** Whether the connection is currently usable. */
    public function isConnected(Connection $connection): bool
    {
        return $this->for($connection)['connected'];
    }

    /**
     * Query the bridge server for a bridge connection's live status, refreshing the cached
     * capabilities. Falls back to cached providers + connected=false when unreachable.
     *
     * @return array{connected: bool, providers: array<int, mixed>, workspaces: array<int, mixed>, posture: array<string, mixed>, bridge_version: string|null, attachment_limits: array<string, int>|null, capabilities: list<string>}
     */
    private function bridgeLiveStatus(Connection $connection): array
    {
        if (empty($connection->connection_key)) {
            return [
                'connected' => false,
                'providers' => $connection->last_providers ?? [],
                'workspaces' => $connection->last_workspaces ?? [],
                'posture' => $connection->last_posture ?? [],
            ] + self::bridgeFields($connection->last_bridge);
        }

        try {
            $relayToken = $this->tokenManager->generate(
                $connection->connection_key,
                ['scope' => TokenManager::INTERNAL_RELAY_SCOPE],
                60,
            );

            $response = Http::withToken($relayToken)
                ->timeout((int) config('ai-bridge.server.relay_timeout', 5))
                ->acceptJson()
                ->get($this->internalApiBase().'/api/status');

            if ($response->successful()) {
                $connected = (bool) $response->json('connected');

                // /api/status only includes `providers`/`connected_at` while a CLI is
                // attached. On a successful-but-disconnected poll keep the cached snapshot
                // rather than erasing it, and prefer the relay's own connect time.
                $providers = $connected
                    ? ($response->json('providers') ?? [])
                    : ($connection->last_providers ?? []);
                // Same rule as providers: /api/status only reports workspaces
                // while a CLI is attached, so a successful-but-disconnected
                // poll must keep the cached snapshot rather than erase it.
                // Absent is NOT empty. A serve process running an older build
                // of this package does not report `workspaces` at all, and
                // treating that as "the bridge allows none" would blank the
                // cache on every poll for the whole of a rolling deploy — the
                // same failure that had to be fixed for providers.
                $reportedWorkspaces = $response->json('workspaces');
                $workspaces = $connected && is_array($reportedWorkspaces)
                    ? $reportedWorkspaces
                    : ($connection->last_workspaces ?? []);
                // Same absent-is-not-empty rule: a serve process that predates
                // this field reports nothing, and blanking the cache on every
                // poll for the length of a rolling deploy is not an improvement.
                $reportedPosture = $response->json('posture');
                $posture = $connected && is_array($reportedPosture)
                    ? $reportedPosture
                    : ($connection->last_posture ?? []);
                // Same rule again. A serve process that predates `bridge`
                // reports none, and a disconnected poll reports none: keep
                // what the machine said last time it connected.
                $reportedBridge = $response->json('bridge');
                $bridge = $connected && is_array($reportedBridge)
                    ? $reportedBridge
                    : ($connection->last_bridge ?? []);
                $connectedAt = $response->json('connected_at');

                $connection->forceFill([
                    'last_providers' => $providers,
                    'last_workspaces' => $workspaces,
                    'last_posture' => $posture,
                    'last_bridge' => $bridge,
                    'last_connected_at' => $connected && $connectedAt !== null
                        ? $connectedAt
                        : $connection->last_connected_at,
                ])->save();

                return ['connected' => $connected, 'providers' => $providers, 'workspaces' => $workspaces, 'posture' => $posture]
                    + self::bridgeFields($bridge);
            }
        } catch (\Throwable $e) {
            Log::info('AI Bridge: bridge status unreachable', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'connected' => false,
            'providers' => $connection->last_providers ?? [],
            'workspaces' => $connection->last_workspaces ?? [],
            'posture' => $connection->last_posture ?? [],
        ] + self::bridgeFields($connection->last_bridge);
    }

    /**
     * The provider/model capabilities a BYOK connection exposes, from chat-completions config.
     *
     * @return array<int, mixed>
     */
    private function byokProviders(): array
    {
        $allowed = (array) config('ai-bridge.chat_completions.allowed_models', []);
        $configured = config('ai-bridge.chat_completions.model');

        $modelIds = $allowed !== [] ? $allowed : array_filter([$configured]);

        $models = array_map(fn ($id) => [
            'id' => $id,
            'name' => $id,
            'is_default' => $id === $configured,
        ], array_values($modelIds));

        return [[
            'name' => 'chat_completions',
            'available' => true,
            'supports_streaming' => true,
            'supports_tools' => true,
            'supports_thinking' => false,
            'supports_session_resume' => false,
            'models' => $models,
        ]];
    }

    /**
     * What is left of the subscription the bridge on this connection is signed in as.
     *
     * Asked live and never cached, unlike the rest of this class. The other fields here are a
     * snapshot worth keeping when the serve process cannot be reached; an allowance figure is
     * not — one from an hour ago is worse than none, because it reads as current.
     *
     * Nothing is stored for the same reason, so there is no `last_usage` column to match
     * `last_posture`. That absence is deliberate.
     *
     * @return array{ok: bool, limits?: array<int, array<string, mixed>>, reason?: string}
     *                                 `reason` is `not_connected`, `unsupported`,
     *                                 `no_credential` or `failed`.
     */
    public function usage(Connection $connection, ?string $provider = null): array
    {
        try {
            $relayToken = $this->tokenManager->generate(
                $connection->connection_key,
                ['scope' => TokenManager::INTERNAL_RELAY_SCOPE],
                60,
            );

            // The serve process holds the question open while the bridge answers, so this
            // waits longer than the ordinary relay calls do.
            $timeout = (int) config('ai-bridge.server.usage_timeout', 12) + 3;

            $response = Http::withToken($relayToken)
                ->timeout($timeout)
                ->acceptJson()
                ->get($this->internalApiBase().'/api/usage', $provider !== null && $provider !== '' ? ['provider' => $provider] : []);
        } catch (\Throwable $e) {
            Log::info('AI Bridge: could not reach the serve process for usage', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'reason' => 'failed'];
        }

        if ($response->status() === 404) {
            return ['ok' => false, 'reason' => 'not_connected'];
        }

        // A bridge that never answered is reported as `failed`, NOT `unsupported`.
        //
        // The two look alike from here and are opposites to the reader. `unsupported` means
        // "this CLI has no such notion" — a permanent fact, and a screen showing it has no
        // reason to ask again. A bridge that was slow, wedged, or had just dropped is a
        // `failed`: the same question a minute later may well answer. Reporting the
        // retryable case as the permanent one is the more expensive way to be wrong, and an
        // old bridge that genuinely does not know the frame reaches the same screen a turn
        // later by a different route anyway.
        if ($response->status() === 504) {
            return ['ok' => false, 'reason' => 'failed'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'reason' => 'failed'];
        }

        $limits = $response->json('limits');

        if ($response->json('ok') === true && is_array($limits) && $limits !== []) {
            return ['ok' => true, 'limits' => array_values($limits)];
        }

        $reason = $response->json('reason');

        return ['ok' => false, 'reason' => is_string($reason) && $reason !== '' ? $reason : 'failed'];
    }

    private function internalApiBase(): string
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
