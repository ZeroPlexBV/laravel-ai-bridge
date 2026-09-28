<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\WebSocket;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Auth\TokenValidationException;
use Tetrix\AiBridge\Contracts\MergesStreamMetadata;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\AiRequestPayload;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\RelayStream;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Support\BridgeLog;
use Tetrix\AiBridge\Tools\ToolRegistry;

/**
 * Handles incoming WebSocket messages from CLI bridge clients.
 *
 * This class processes the AI Bridge Protocol messages and routes them
 * appropriately — hello messages trigger authentication, stream events
 * are dispatched to the appropriate StreamHandler, etc.
 *
 * Usage by the consuming app's WebSocket server:
 *   $handler = app(MessageHandler::class);
 *   $handler->handleMessage($connectionId, $connection, $rawJson);
 */
class MessageHandler
{
    /**
     * Request IDs that have already been recovered once from a lost CLI
     * session. A fresh re-issue (cli_session_id=null) cannot itself produce
     * session_lost, but this guards against a recovery loop regardless.
     *
     * @var array<string, true>
     */
    private array $recoveredRequests = [];

    /**
     * The `reason` values a `usage_result` may carry, per PROTOCOL.md.
     *
     * An allowlist rather than a passthrough: this is an enum the consuming application
     * branches on, and a value it has never heard of is indistinguishable from a bug in it.
     */
    private const USAGE_REASONS = ['unsupported', 'no_credential', 'failed'];

    /**
     * The `reason` values a rejecting `turn_input_ack` may carry, per PROTOCOL.md.
     *
     * An allowlist for the same reason as USAGE_REASONS: the application branches on it —
     * `turn_not_running` is the one that makes it start a new turn with the message.
     */
    private const TURN_INPUT_REASONS = ['turn_not_running', 'input_not_open'];

    public function __construct(
        private readonly BridgeConnectionManager $connectionManager,
        private readonly TokenManager $tokenManager,
        private readonly ToolRegistry $toolRegistry,
    ) {}

    /**
     * Register a relayed (PHP-FPM) request as pending in the serve process.
     *
     * Requests issued under PHP-FPM are relayed to the bridge via the internal
     * HTTP API; their response events arrive at this separate serve process. The
     * PHP-FPM worker that issued the request never sees those events, so without
     * a pending request here tool calls would be rejected (no recorded owner) and
     * stream events would be dropped ("unknown request").
     *
     * This wires a RelayStream whose StreamHandler streams events into the
     * per-turn stream-event buffer and persists the assistant row when the turn
     * completes. The browser tails the buffer over SSE.
     *
     * Cleanup is handled by the existing done/error/cancelled handlers, which
     * already call removePendingRequest().
     */
    public function registerRelayedRequest(string $requestId, string $userId, string $conversationId): void
    {
        $relay = new RelayStream($requestId, $conversationId);

        $this->connectionManager->registerPendingRequest(
            $requestId,
            $relay->getStreamHandler(),
            $userId,
        );
    }

    /**
     * Handle a raw incoming WebSocket message.
     *
     * @param  string  $connectionId  Unique identifier for this WebSocket connection.
     * @param  mixed  $connection  The underlying WebSocket connection object.
     * @param  string  $rawMessage  The raw JSON message string.
     * @return array<string, mixed>|null  Response message to send back, or null.
     */
    public function handleMessage(string $connectionId, mixed $connection, string $rawMessage): ?array
    {
        $message = json_decode($rawMessage, true);

        if (! is_array($message) || ! isset($message['type'])) {
            Log::warning('AI Bridge: received invalid WebSocket message', [
                'connection_id' => $connectionId,
                'raw' => substr($rawMessage, 0, 500),
            ]);

            return [
                'type' => MessageTypes::CONNECTION_ERROR,
                'error' => 'invalid_message',
                'message' => 'Message must be valid JSON with a "type" field.',
            ];
        }

        $type = $message['type'];

        if (! MessageTypes::isValid($type)) {
            Log::warning('AI Bridge: received unknown message type', [
                'connection_id' => $connectionId,
                'type' => $type,
            ]);

            return null;
        }

        return match ($type) {
            MessageTypes::HELLO => $this->handleHello($connectionId, $connection, $message),
            MessageTypes::PROVIDERS_UPDATE => $this->handleProvidersUpdate($connectionId, $message),
            MessageTypes::PING => $this->handlePing($connectionId, $message),
            MessageTypes::AI_REQUEST_ACK => $this->handleAiRequestAck($connectionId, $message),
            MessageTypes::POSTURE => $this->handlePosture($connectionId, $message),
            MessageTypes::USAGE_RESULT => $this->handleUsageResult($connectionId, $message),
            MessageTypes::TURN_INPUT_ACK => $this->handleTurnInputAck($connectionId, $message),
            MessageTypes::STREAM => $this->handleStreamEnvelope($connectionId, $message),
            MessageTypes::TOOL_CALL => $this->handleToolCall($connectionId, $message),
            MessageTypes::ERROR => $this->handleError($connectionId, $message),
            MessageTypes::CANCELLED => $this->handleCancelled($connectionId, $message),
            default => null,
        };
    }

    /**
     * Handle a 'hello' message — complete the protocol handshake.
     *
     * Authentication can happen in two ways:
     * 1. Pre-authenticated: The dedicated BridgeWebSocketServer validates the JWT
     *    at connection time (via ?token= URL param). The connection is already
     *    registered in BridgeConnectionManager before the hello arrives.
     * 2. Token in hello body: For custom WebSocket integrations where authentication
     *    happens via the hello message instead. Falls back to this if not pre-authed.
     *
     * @return array<string, mixed>  Welcome or connection_error response.
     */
    private function handleHello(string $connectionId, mixed $connection, array $message): array
    {
        $protocolVersion = $message['version'] ?? $message['protocol_version'] ?? 'unknown';

        // Validate protocol version compatibility — reject incompatible major
        // versions. Minor version differences are allowed (additive changes).
        $supportedMajorVersion = 0; // Current protocol is v0.1
        if ($protocolVersion !== 'unknown') {
            $versionParts = explode('.', ltrim($protocolVersion, 'v'));
            $clientMajor = (int) ($versionParts[0] ?? 0);
            if ($clientMajor !== $supportedMajorVersion) {
                Log::warning('AI Bridge: protocol version mismatch', [
                    'connection_id' => $connectionId,
                    'client_version' => $protocolVersion,
                    'supported_major' => $supportedMajorVersion,
                ]);

                return [
                    'type' => MessageTypes::CONNECTION_ERROR,
                    'error' => 'protocol_version_mismatch',
                    'message' => "Incompatible protocol version: server supports major version {$supportedMajorVersion}, client sent {$protocolVersion}. Please update your bridge client.",
                ];
            }
        }

        // Check if this connection was already authenticated at connect time
        // (e.g. by BridgeWebSocketServer via ?token= URL param)
        $existingUserId = $this->connectionManager->getUserIdByConnectionId($connectionId);

        if ($existingUserId !== null) {
            // Already authenticated — store providers from hello and return welcome
            $providers = self::asList($message['providers'] ?? null);
            $this->connectionManager->setProviders($existingUserId, $providers);
            $this->connectionManager->setWorkspaces($existingUserId, self::asList($message['workspaces'] ?? null));

            $this->logBridgeConnection($existingUserId, $connectionId, $protocolVersion, $providers, 'pre-authenticated');

            return $this->buildWelcomeResponse($connectionId, $existingUserId);
        }

        // Not pre-authenticated — require token in the hello body
        $token = $message['token'] ?? '';

        if (empty($token)) {
            return [
                'type' => MessageTypes::CONNECTION_ERROR,
                'error' => 'missing_token',
                'message' => 'Hello message must include a "token" field (or authenticate via ?token= URL param).',
            ];
        }

        try {
            $decoded = $this->tokenManager->validate($token);
            $userId = (string) $decoded->sub;
        } catch (TokenValidationException $e) {
            Log::info('AI Bridge: bridge authentication failed', [
                'connection_id' => $connectionId,
                'error_code' => $e->errorCode,
            ]);

            return [
                'type' => MessageTypes::CONNECTION_ERROR,
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
            ];
        }

        // Register the connection with provider capabilities
        $providers = self::asList($message['providers'] ?? null);
        $this->connectionManager->addConnection(
            $userId,
            $connectionId,
            $connection,
            $providers,
            isset($decoded->exp) ? (int) $decoded->exp : null,
            isset($decoded->cid) ? (int) $decoded->cid : null,
        );

        // Recorded after addConnection(), which is what creates the entry the
        // setter writes into.
        $this->connectionManager->setWorkspaces($userId, self::asList($message['workspaces'] ?? null));

        $this->logBridgeConnection($userId, $connectionId, $protocolVersion, $providers, 'connected');

        return $this->buildWelcomeResponse($connectionId, $userId);
    }

    /**
     * The allowance figures a bridge was asked for, handed to whoever is waiting.
     *
     * Shaped like handlePosture(): resolve the user first and bail if the handshake never
     * completed, then type-check every field. A TypeError inside a ReactPHP data callback
     * takes the whole serve process down, so a malformed frame must be dropped rather than
     * trusted.
     *
     * Returns null: the answer goes to the waiting HTTP response, not back to the bridge.
     */
    private function handleUsageResult(string $connectionId, array $message): ?array
    {
        $userId = $this->connectionManager->getUserIdByConnectionId($connectionId);

        if ($userId === null) {
            Log::warning('AI Bridge: usage_result from unauthenticated connection', [
                'connection_id' => $connectionId,
            ]);

            return null;
        }

        $requestId = $message['id'] ?? null;

        if (! is_string($requestId) || $requestId === '') {
            Log::warning('AI Bridge: usage_result without an id', [
                'connection_id' => $connectionId,
            ]);

            return null;
        }

        $limits = [];

        foreach (is_array($message['limits'] ?? null) ? $message['limits'] : [] as $limit) {
            if (! is_array($limit)) {
                continue;
            }

            $label = $limit['label'] ?? null;
            $percent = $limit['percent'] ?? null;

            // A row needs a name and a figure to mean anything. A partial row is worse than
            // an absent one: a bar with no label cannot be read.
            if (! is_string($label) || $label === '' || ! is_numeric($percent)) {
                continue;
            }

            $row = [
                'label' => $label,
                'percent' => (int) round(max(0, min(100, (float) $percent))),
            ];

            foreach (['resets_at', 'kind', 'group'] as $field) {
                if (is_string($limit[$field] ?? null) && $limit[$field] !== '') {
                    $row[$field] = $limit[$field];
                }
            }

            $limits[] = $row;
        }

        // `reason` reaches the application as an enum it will branch on and probably render,
        // so only the documented values pass. Anything else — a newer bridge, a broken one —
        // becomes `failed`, which is the honest summary of "it did not work and I cannot
        // tell you more" and cannot surprise a `match` downstream.
        $reason = is_string($message['reason'] ?? null)
            && in_array($message['reason'], self::USAGE_REASONS, true)
                ? $message['reason']
                : null;
        $ok = ($message['ok'] ?? null) === true && $limits !== [];

        $this->connectionManager->resolvePendingUsage($requestId, $userId, $ok
            ? ['ok' => true, 'limits' => $limits]
            // A bridge that said ok but sent nothing usable is not the same as one reporting
            // an empty allowance, and must not be presented as "nothing used".
            : ['ok' => false, 'reason' => $reason ?? 'failed']);

        return null;
    }

    /**
     * The bridge's answer to a mid-turn message, handed to whoever is waiting.
     *
     * Shaped like handleUsageResult(): the sender must have completed the handshake, and
     * every field is type-checked, because a TypeError here takes the serve process down.
     * Only `accepted` means accepted; anything else is a rejection, so a confused frame can
     * never make the application believe a message reached the assistant.
     *
     * Returns null: the answer goes to the waiting HTTP response, not back to the bridge.
     *
     * @param  array<string, mixed>  $message
     */
    private function handleTurnInputAck(string $connectionId, array $message): ?array
    {
        $userId = $this->connectionManager->getUserIdByConnectionId($connectionId);

        if ($userId === null) {
            Log::warning('AI Bridge: turn_input_ack from unauthenticated connection', [
                'connection_id' => $connectionId,
            ]);

            return null;
        }

        $requestId = $message['request_id'] ?? null;
        $messageId = $message['message_id'] ?? null;

        if (! is_string($requestId) || $requestId === '' || ! is_string($messageId) || $messageId === '') {
            Log::warning('AI Bridge: turn_input_ack without a request_id or message_id', [
                'connection_id' => $connectionId,
            ]);

            return null;
        }

        if (($message['status'] ?? null) === 'accepted') {
            $answer = ['status' => 'accepted'];
        } else {
            $reason = $message['reason'] ?? null;
            $answer = ['status' => 'rejected'];
            if (is_string($reason) && in_array($reason, self::TURN_INPUT_REASONS, true)) {
                $answer['reason'] = $reason;
            }
        }

        $this->connectionManager->resolvePendingTurnInput($requestId, $messageId, $userId, $answer);

        return null;
    }

    /**
     * Handle a 'posture' message — the bridge reporting the CLI isolation it
     * actually adopted for this connection.
     *
     * Recorded rather than acted on. The bridge is the authority: it has
     * already decided, and a server cannot argue with a refusal that exists
     * precisely to stop servers overriding the machine's owner. What this
     * buys is that the app can SHOW it, so "the assistant has no tools" stops
     * being a mystery only visible in a log on the operator's laptop.
     *
     * @param  array<string, mixed>  $message
     */
    private function handlePosture(string $connectionId, array $message): ?array
    {
        $userId = $this->connectionManager->getUserIdByConnectionId($connectionId);

        if ($userId === null) {
            // A posture frame before the handshake completed — ignore.
            Log::warning('AI Bridge: posture from unauthenticated connection', [
                'connection_id' => $connectionId,
            ]);

            return null;
        }

        $adopted = is_string($message['cli_isolation'] ?? null) ? $message['cli_isolation'] : null;

        if ($adopted === null) {
            Log::warning('AI Bridge: posture frame without a cli_isolation', [
                'connection_id' => $connectionId,
            ]);

            return null;
        }

        $posture = ['cli_isolation' => $adopted];

        foreach (['requested', 'reason', 'message'] as $field) {
            $value = $message[$field] ?? null;
            if (is_string($value) || $value === null) {
                $posture[$field] = $value;
            }
        }

        $this->connectionManager->setPosture($userId, $posture);

        if (($posture['reason'] ?? null) !== null) {
            // Worth a log line of its own: this is the case where the app is
            // configured for one thing and the bridge is doing another.
            Log::info('AI Bridge: bridge declined the requested CLI isolation', [
                'user_id' => $userId,
                'requested' => $posture['requested'] ?? null,
                'adopted' => $adopted,
                'reason' => $posture['reason'],
            ]);
        }

        return null;
    }

    /**
     * Handle a 'providers_update' message — the bridge re-detected its local
     * CLIs mid-connection and the available set changed.
     *
     * Refreshes the connection's advertised providers in the connection
     * manager (the same store hello populates), so the host application's
     * provider list reflects what the bridge can currently run. No response
     * is sent — this is a one-way notification.
     *
     * @param  array<string, mixed>  $message
     */
    private function handleProvidersUpdate(string $connectionId, array $message): ?array
    {
        $userId = $this->connectionManager->getUserIdByConnectionId($connectionId);

        if ($userId === null) {
            // A providers_update before the hello handshake completed — ignore.
            Log::warning('AI Bridge: providers_update from unauthenticated connection', [
                'connection_id' => $connectionId,
            ]);

            return null;
        }

        $providers = self::asList($message['providers'] ?? null);
        $this->connectionManager->setProviders($userId, $providers);

        $this->logBridgeConnection($userId, $connectionId, 'n/a', $providers, 'providers updated');

        return null;
    }

    /**
     * Coerce a hello/providers_update field to a list.
     *
     * These arrive as parsed JSON from a client, and they are handed straight
     * to methods declaring `array`. Under `strict_types=1` a string where an
     * array is declared is a TypeError — raised inside the ReactPHP message
     * callback, which has no try/catch of its own, so it does not fail one
     * handshake, it exits the process and drops every connected bridge.
     *
     * @param  mixed  $value
     * @return array<int, mixed>
     */
    private static function asList(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Build the standard welcome response sent after successful handshake.
     *
     * Carries a `refreshed_token` when the bridge's current token is past half
     * its life — see maybeRefreshToken().
     */
    private function buildWelcomeResponse(string $connectionId, string $userId): array
    {
        $welcome = [
            'type' => MessageTypes::WELCOME,
            'session_id' => $connectionId,
            'tools' => $this->toolRegistry->toArray(),
            'config' => [
                'heartbeat_interval' => (int) config('ai-bridge.websocket.heartbeat_interval', 30),
                'request_timeout' => (int) config('ai-bridge.websocket.request_timeout', 86400),
                // The bound that actually kills. A bridge too old to know the
                // field ignores it and keeps its own behaviour, so this can
                // ship without the two moving in step.
                'silence_timeout' => (int) config('ai-bridge.websocket.silence_timeout', 900),
            ],
            // How much the local CLI environment is allowed to influence
            // behaviour. The bridge translates this into a different per-
            // provider flag set: `isolated` means MCP-only tools, no
            // autonomous shell/edit, `--bare` for Claude, no other MCP
            // servers, and a neutral fallback system prompt when the server
            // didn't send one. `native` is the legacy posture and keeps the
            // operator's full local environment (`bypassPermissions` /
            // `danger-full-access` / `--yolo`, user CLAUDE.md / AGENTS.md /
            // skills / hooks, configured MCP servers, default system prompt)
            // intact. The bridge defaults to `isolated` when the field is
            // absent, so the safe default holds for older bridges too — but
            // we send it explicitly for clarity.
            'cli_isolation' => $this->resolveCliIsolation(),
        ];

        $refreshedToken = $this->maybeRefreshToken($userId);
        if ($refreshedToken !== null) {
            $welcome['refreshed_token'] = $refreshedToken;
        }

        return $welcome;
    }

    /**
     * Resolve the `cli_isolation` posture for outgoing welcome messages.
     *
     * Reads `ai-bridge.cli.isolation` and normalizes anything not explicitly
     * recognised back to `"isolated"`. Typos and unexpected values are then
     * default-safe rather than default-leaky — only an exact opt-in counts.
     *
     * The three postures:
     *   - `isolated` (default): the CLI reaches server-declared tools only.
     *   - `workspace`: the CLI also gets its own file and shell tools, inside
     *     the directory the request named, while the operator's own MCP
     *     servers, hooks and plugins stay out. This is what a chat that edits
     *     a repository needs, and it is NOT a sandbox — the bridge's README
     *     says so at length. Pointless without a bridge started with
     *     `--allow-dir` and a `working_dir` on the request; the CLI simply
     *     works in the empty scratch directory instead.
     *   - `native`: everything on, including the operator's environment. Never
     *     appropriate when the server is reachable by end users.
     */
    private function resolveCliIsolation(): string
    {
        $value = config('ai-bridge.cli.isolation', 'isolated');

        return in_array($value, ['native', 'workspace'], true) ? $value : 'isolated';
    }

    /**
     * Re-issue a CLI bridge's connection token if it is past half its life.
     *
     * Bridges are semi-permanent, but their tokens still expire. Rather than
     * mint a new JWT on every request, the server tops the token up only once
     * it is older than half the configured bridge TTL — so in steady state a
     * refresh happens roughly once per half-TTL. Called both at the handshake
     * (delivered via `welcome.refreshed_token`) and by the server's periodic
     * timer (delivered via a `token_refresh` message) so a bridge that keeps
     * reconnecting AND one that stays connected for the full window are both
     * covered.
     *
     * @return string|null  A fresh token, or null when no refresh is due.
     */
    public function maybeRefreshToken(string $userId): ?string
    {
        // Only managed CLI bridge tokens carry a `cid` claim (added in
        // ConnectionController::generateBridgeToken()). Both auth paths —
        // BridgeWebSocketHandler::onOpen() (?token= URL param) and the
        // hello-body fallback — record the JWT's `cid` and `exp` when present,
        // so a managed bridge is eligible for refresh regardless of which path
        // authenticated it. Legacy user-scoped tokens (no `cid`) and any other
        // connection without recorded claims are deliberately left alone:
        // only tokens the server itself minted with a `cid` are refreshable.
        $cid = $this->connectionManager->getTokenCid($userId);
        $expiresAt = $this->connectionManager->getTokenExpiresAt($userId);
        if ($cid === null || $expiresAt === null) {
            return null;
        }

        $bridgeTtl = (int) config('ai-bridge.token.bridge_ttl', 2592000);

        // Not due yet — more than half the lifetime still remains.
        if (($expiresAt - time()) > intdiv($bridgeTtl, 2)) {
            return null;
        }

        $token = $this->tokenManager->generate($userId, ['cid' => $cid], $bridgeTtl);

        // Record the new expiry so the token is not refreshed again until it
        // next ages out.
        $this->connectionManager->setTokenExpiresAt($userId, time() + $bridgeTtl);

        return $token;
    }

    /**
     * Log a bridge connection event with provider details.
     */
    private function logBridgeConnection(string $userId, string $connectionId, string $protocolVersion, array $providers, string $label): void
    {
        $availableProviders = array_filter($providers, fn ($p) => ($p['available'] ?? false));
        Log::info("AI Bridge: bridge {$label}", [
            'user_id' => $userId,
            'connection_id' => $connectionId,
            'protocol_version' => $protocolVersion,
            'providers' => array_map(fn ($p) => $p['name'] ?? 'unknown', $availableProviders),
        ]);
    }

    /**
     * Handle a 'ping' message — bridge checking server liveness.
     *
     * Per PROTOCOL.md, the bridge sends ping and the server responds with pong.
     */
    private function handlePing(string $connectionId, array $message): ?array
    {
        // FIRST STATEMENT, ahead of the log line as well as the poll. An
        // unwritable log destination throws from Log::debug like anything else,
        // and the cost of a dropped pong is the same whatever threw.
        //
        // The poll below cannot stop it going out either. A throw there would
        // otherwise be caught by the socket handler,
        // which returns without sending a response — and a bridge that misses a
        // pong by 10 seconds declares the connection dead and reconnects, which
        // replaces the connection and fails EVERY in-flight turn for that user.
        // A deterministic throw would do that on every heartbeat, forever. The
        // liveness answer does not depend on this work, so it does not wait for
        // it.
        $pong = [
            'type' => MessageTypes::PONG,
            'timestamp' => $message['timestamp'] ?? time(),
        ];

        Log::debug('AI Bridge: ping received', ['connection_id' => $connectionId]);

        // The heartbeat is the only thing a turn that has gone quiet still
        // produces, so it is where a stop has to be noticed.
        //
        // The abort flag was polled in exactly one place: as each stream event
        // arrived. That reads the flag often while the model is writing, and
        // NEVER while it is not -- so a turn three minutes into a build, which
        // is precisely when somebody presses stop, ignored the button
        // completely. The endpoint returned "abort_requested", the chat moved
        // on, and the CLI ran to the end on the operator's machine.
        // Belt as well as braces: each turn is guarded individually inside, so
        // one bad one cannot starve the others — and this outer catch makes the
        // promise above true for the whole call rather than for the loop body,
        // including the lookups before the loop starts.
        try {
            $this->pollAbortsForConnection($connectionId);
        } catch (\Throwable $e) {
            BridgeLog::warning('failed to poll abort flags on the heartbeat', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
            ]);
        }

        return $pong;
    }

    /**
     * Handle an 'ai_request_ack' message — bridge acknowledges receipt of ai_request.
     */
    private function handleAiRequestAck(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $cliSessionId = $message['cli_session_id'] ?? null;

        Log::debug('AI Bridge: ai_request acknowledged', [
            'connection_id' => $connectionId,
            'request_id' => $requestId,
            'cli_session_id' => $cliSessionId,
        ]);

        // What the bridge resolved for this turn's session defaults — the
        // prompt mode it applied, and which environment keys it took or
        // dropped. The field exists so a server can ASSERT it got what it
        // asked for rather than inferring it from the assistant's behaviour
        // several turns later, and a value nobody ever reads asserts nothing.
        //
        // Absent from a bridge older than 0.12.0, which is why this is a
        // separate line rather than three more keys on the one above: absence
        // means UNKNOWN, and a log entry reading `prompt_mode: null` on every
        // turn would read as "the bridge applied nothing" — the one conclusion
        // that is never safe to draw from silence.
        $session = $message['bridge_session'] ?? null;
        if (is_array($session)) {
            $rejected = is_array($session['env_rejected'] ?? null) ? $session['env_rejected'] : [];

            // A rejected key is the server and the bridge disagreeing about
            // what this protocol contains — normally version skew, and the
            // turn still ran. Worth a warning rather than a debug line,
            // because nothing else on this side will ever mention it.
            if ($rejected !== []) {
                Log::warning('AI Bridge: bridge dropped env keys it does not allow', [
                    'connection_id' => $connectionId,
                    'request_id' => $requestId,
                    'env_rejected' => $rejected,
                ]);
            }

            Log::debug('AI Bridge: bridge session defaults', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
                'prompt_mode' => $session['prompt_mode'] ?? null,
                'prompt_server_text' => $session['prompt_server_text'] ?? null,
                'env_overridden' => $session['env_overridden'] ?? [],
            ]);
        }

        // The bridge confirming that this turn's input is open, so a message
        // can reach it while it runs. Written to the turn's stream metadata,
        // because the process that has to read it — the PHP-FPM worker holding
        // the person's next message — is not this one. Only `true` counts, and
        // only from the bridge that owns the turn: this is what makes the
        // application send a message to a turn rather than hold it.
        //
        // Absent from a bridge that predates turn input, and from any turn
        // that did not ask for it: absence means closed, which is what every
        // turn meant before.
        if (($message['input_open'] ?? null) === true
            && is_string($requestId) && $requestId !== ''
            && $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            $this->markInputOpen($requestId);
        }

        return null;
    }

    /**
     * Record in the turn's stream metadata that its input is open.
     *
     * A store that cannot merge metadata (a custom driver written before the
     * capability existed) simply does not record it, and the application then
     * holds a mid-turn message as before. Failures are logged, never thrown:
     * this runs inside the event loop.
     */
    private function markInputOpen(string $requestId): void
    {
        try {
            $store = app(StreamStoreContract::class);

            if ($store instanceof MergesStreamMetadata) {
                $store->mergeMetadata($requestId, ['input_open' => true]);
            }
        } catch (\Throwable $e) {
            BridgeLog::warning('failed to record input_open in the stream metadata', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a 'stream' envelope message from the bridge.
     *
     * Per PROTOCOL.md, all streaming events arrive in an envelope:
     *   { "type": "stream", "request_id": "...", "event": "<event_type>", "data": {...} }
     *
     * The "event" field contains the actual event type (block_start, block_delta,
     * block_stop, tool_result, done, error, tool_call).
     */
    private function handleStreamEnvelope(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $eventType = $message['event'] ?? '';

        if (empty($requestId)) {
            Log::warning('AI Bridge: stream envelope missing request_id', [
                'connection_id' => $connectionId,
            ]);

            return null;
        }

        if (empty($eventType)) {
            Log::warning('AI Bridge: stream envelope missing event field', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        // Handle tool_call events inside the stream envelope specially
        if ($eventType === MessageTypes::TOOL_CALL) {
            return $this->handleToolCallFromStream($connectionId, $message);
        }

        // Handle done events — need to clean up pending request
        if ($eventType === MessageTypes::DONE) {
            return $this->handleDoneFromStream($connectionId, $message);
        }

        // Handle error events inside stream — need to clean up pending request
        if ($eventType === MessageTypes::ERROR) {
            return $this->handleErrorFromStream($connectionId, $message);
        }

        // For block_start, block_delta, block_stop, tool_result — route to StreamHandler
        $handler = $this->connectionManager->getPendingRequest($requestId);
        if (! $handler) {
            Log::debug('AI Bridge: received stream event for unknown request', [
                'request_id' => $requestId,
                'event' => $eventType,
            ]);

            return null;
        }

        // Verify the sender owns the pending request. Fails closed: reject the
        // event if the sender is unregistered or does not own the request.
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            $senderUserId = $this->connectionManager->getUserIdByConnectionId($connectionId);
            $requestUserId = $this->connectionManager->getPendingRequestUserId($requestId);
            Log::warning('AI Bridge: stream event from wrong or unregistered user, discarding', [
                'request_id' => $requestId,
                'sender_user_id' => $senderUserId,
                'request_user_id' => $requestUserId,
            ]);

            return null;
        }

        // Check the abort flag in the stream buffer before forwarding the event.
        // If the user has clicked "stop" since the last event, dispatch a
        // cancelled terminal locally and send a cancel frame to the bridge so
        // the CLI exits. Polling here is cheap (one Redis EXISTS per event).
        if ($this->isAbortRequested($requestId)) {
            $this->handleUserAbort($connectionId, $requestId, $handler);

            return null;
        }

        $event = StreamEvent::fromArray($message);
        $handler->dispatchEvent($event);

        return null;
    }

    /**
     * Check the stream buffer for a user-requested abort.
     *
     * Swallows store failures (a transient Redis blip should not break the
     * stream itself — the worst case without polling is the turn finishes
     * normally and the user has to wait an extra moment).
     */
    private function isAbortRequested(string $requestId): bool
    {
        try {
            return app(StreamStoreContract::class)->isAborted($requestId);
        } catch (\Throwable $e) {
            BridgeLog::warning('failed to poll stream-store abort flag', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Stop any turn on this connection that somebody has asked to stop.
     *
     * Bounded by the heartbeat interval (30s by default), which is the price of
     * not running a timer in the WebSocket process. A turn that IS producing
     * events is still stopped at the next one, as it always was.
     */
    private function pollAbortsForConnection(string $connectionId): void
    {
        $userId = $this->connectionManager->getUserIdByConnectionId($connectionId);
        // '' as well as null. A request registered without an owner — the
        // default of registerPendingRequest() — would otherwise be matched by
        // ANY connection whose own user id is empty, which is the one case
        // verifySenderOwnsRequest() fails closed on. Everywhere else this path
        // and that check agree; this is the only way they could disagree, and
        // it disagrees in the permissive direction.
        if ($userId === null || $userId === '') {
            return;
        }

        foreach ($this->connectionManager->pendingRequestIdsForUser($userId) as $requestId) {
            // PER TURN, not around the loop. One handler that throws
            // deterministically would otherwise skip every request after it, on
            // every heartbeat — and iteration follows insertion order, so it
            // would be the same turns every time, never stopped. That is the
            // "the stop does nothing" fault this poll exists to fix, rebuilt in
            // a corner of the fix.
            try {
                $handler = $this->connectionManager->getPendingRequest($requestId);
                if ($handler === null) {
                    continue;
                }
                if ($this->isAbortRequested($requestId)) {
                    $this->handleUserAbort($connectionId, $requestId, $handler);
                }
            } catch (\Throwable $e) {
                BridgeLog::warning('failed to stop a turn on the heartbeat', [
                    'connection_id' => $connectionId,
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Dispatch a cancelled terminal locally and send a cancel frame to the bridge.
     *
     * Called when an abort flag is observed mid-turn. Idempotent — the
     * StreamHandler guards against double-termination, and the bridge handles
     * a cancel for an already-terminated request gracefully.
     */
    private function handleUserAbort(string $connectionId, string $requestId, StreamHandler $handler): void
    {
        BridgeLog::info('user-requested abort observed, cancelling turn', [
            'request_id' => $requestId,
        ]);

        $userId = $this->connectionManager->getUserIdByConnectionId($connectionId);
        if ($userId !== null) {
            $this->connectionManager->sendToUser($userId, [
                'type' => MessageTypes::CANCEL,
                'request_id' => $requestId,
            ]);
        }

        $handler->dispatchCancelled('Cancelled by user.');
        $this->connectionManager->removePendingRequest($requestId);
        unset($this->recoveredRequests[$requestId]);
    }

    /**
     * Handle a tool_call event from within a stream envelope.
     *
     * The bridge is relaying the AI's request to execute a tool.
     * We execute it locally and send back a tool_resolve message.
     *
     * Applies the same ownership check as top-level tool_call messages so a
     * bridge client cannot execute tools against a request it does not own.
     */
    private function handleToolCallFromStream(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';

        // SEC: Verify the sender owns this request before executing any tool.
        // Mirrors the check in handleToolCall() for top-level tool_call messages.
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            Log::warning('AI Bridge: stream-envelope tool_call from wrong or unregistered user, discarding', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        $data = $message['data'] ?? [];

        return $this->executeToolCall(
            $requestId,
            $data['tool_name'] ?? '',
            $data['parameters'] ?? [],
            $data['tool_call_id'] ?? $data['call_id'] ?? '',
        );
    }

    /**
     * Handle a 'tool_call' message from the bridge (top-level, non-envelope format).
     *
     * The bridge is relaying the AI's request to execute a tool.
     * We execute it locally and send back a tool_resolve message.
     */
    private function handleToolCall(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';

        // Apply the same ownership check as handleStreamEnvelope() so a bridge
        // client cannot execute tools registered for another user's request.
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            Log::warning('AI Bridge: tool_call from wrong user (top-level), discarding', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        // The bridge client sends a top-level tool_call with `tool_call_id`,
        // `tool_name` and `arguments` (see ai-bridge bridge.ts). Older/envelope
        // field names (`data.*`, `call_id`, `parameters`) are accepted as
        // fallbacks for compatibility.
        return $this->executeToolCall(
            $requestId,
            $message['tool_name'] ?? $message['data']['tool_name'] ?? '',
            $message['arguments'] ?? $message['parameters'] ?? $message['data']['parameters'] ?? $message['data']['arguments'] ?? [],
            $message['tool_call_id'] ?? $message['call_id'] ?? $message['data']['tool_call_id'] ?? $message['data']['call_id'] ?? '',
        );
    }

    /**
     * Execute a tool call: dispatch to StreamHandler callbacks, run the tool, return response.
     *
     * Shared implementation for both stream-envelope and top-level tool_call messages.
     */
    private function executeToolCall(string $requestId, string $toolName, array $params, string $callId): ?array
    {
        $handler = $this->connectionManager->getPendingRequest($requestId);

        // If the request is no longer pending (stream already terminated),
        // return a tool_error to prevent the bridge from hanging.
        if (! $handler) {
            Log::warning('AI Bridge: tool call received for completed/unknown request', [
                'request_id' => $requestId,
                'tool' => $toolName,
            ]);

            return [
                'type' => MessageTypes::TOOL_ERROR,
                'request_id' => $requestId,
                'tool_call_id' => $callId,
                'error' => 'request_already_completed',
            ];
        }

        // Validate tool_name before dispatching so consuming-app callbacks are
        // never invoked with an empty tool name.
        if (empty($toolName)) {
            Log::warning('AI Bridge: tool call received with empty tool_name', [
                'request_id' => $requestId,
            ]);

            return [
                'type' => MessageTypes::TOOL_ERROR,
                'request_id' => $requestId,
                'tool_call_id' => $callId,
                'error' => 'Tool call received with empty tool_name — check the bridge client is sending a valid tool_name field.',
            ];
        }

        // Enforce the conversation's tool allowlist at execution time. Advertising
        // a filtered tool set is not enough on its own — a forged or compromised
        // bridge client could send a tool_call for a tool this conversation never
        // exposed, so the runtime guard is the actual boundary.
        if (! $this->toolAllowedForConversation($handler, $toolName)) {
            Log::warning('AI Bridge: tool call rejected by conversation allowlist', [
                'request_id' => $requestId,
                'conversation_id' => $handler->getConversationId(),
                'tool' => $toolName,
            ]);

            return [
                'type' => MessageTypes::TOOL_ERROR,
                'request_id' => $requestId,
                'tool_call_id' => $callId,
                'error' => "Tool '{$toolName}' is not allowed for this conversation.",
            ];
        }

        // Tool execution is synchronous and runs on the ReactPHP event loop
        // thread — a slow tool blocks all WebSocket events for every connected
        // user. Tool handlers registered via AiBridge::registerTool() must be
        // non-blocking when the bridge server runs under ReactPHP.

        // Dispatch to the StreamHandler's tool call callbacks
        $handler->dispatchToolCall($toolName, $params, $callId);

        // Execute the tool if registered
        if ($this->toolRegistry->has($toolName)) {
            // Inject this call's conversation so handlers can scope their work
            // (e.g. resolve which tenant/record the call acts on) without the AI
            // having to pass — or even know — the id. Cleared in finally.
            $toolContext = app(\Tetrix\AiBridge\Tools\ToolContext::class);
            $toolContext->setConversationId($handler->getConversationId());

            try {
                $result = $this->toolRegistry->execute($toolName, $params);

                // Validate that the result is JSON-serializable
                if (json_encode($result) === false) {
                    Log::error('AI Bridge: tool result not JSON-serializable', [
                        'tool' => $toolName,
                        'json_error' => json_last_error_msg(),
                    ]);

                    return [
                        'type' => MessageTypes::TOOL_ERROR,
                        'request_id' => $requestId,
                        'tool_call_id' => $callId,
                        'error' => 'Tool execution failed',
                    ];
                }

                return [
                    'type' => MessageTypes::TOOL_RESOLVE,
                    'request_id' => $requestId,
                    'tool_call_id' => $callId,
                    'result' => $result,
                ];
            } catch (\Throwable $e) {
                // Return a generic message to the client, log the full exception server-side.
                Log::error('AI Bridge: tool execution failed', [
                    'tool' => $toolName,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return [
                    'type' => MessageTypes::TOOL_ERROR,
                    'request_id' => $requestId,
                    'tool_call_id' => $callId,
                    'error' => 'Tool execution failed',
                ];
            } finally {
                $toolContext->forget();
            }
        }

        Log::warning('AI Bridge: tool not registered', ['tool' => $toolName]);

        return [
            'type' => MessageTypes::TOOL_ERROR,
            'request_id' => $requestId,
            'tool_call_id' => $callId,
            'error' => "Tool '{$toolName}' is not registered.",
        ];
    }

    /**
     * Whether a conversation may execute a given tool — the runtime side of the
     * advertised allowlist. A conversation with `allowed_tools = null` (or a
     * non-persisted / direct stream whose id isn't a conversation key) allows
     * everything, preserving prior behaviour; otherwise the tool must be listed.
     *
     * The allowlist is resolved from the DB at most once per request and memoized
     * on the StreamHandler, so a multi-tool turn doesn't re-query on every call
     * (tool execution runs on the shared event loop).
     */
    private function toolAllowedForConversation(StreamHandler $handler, string $toolName): bool
    {
        if (! $handler->hasResolvedAllowedTools()) {
            $conversationId = $handler->getConversationId();
            $allowed = ($conversationId === '' || ! is_numeric($conversationId))
                ? null
                : Conversation::query()->whereKey($conversationId)->first(['allowed_tools'])?->allowed_tools;

            $handler->cacheAllowedTools(is_array($allowed) ? array_values($allowed) : null);
        }

        $allowed = $handler->getAllowedTools();

        return $allowed === null || in_array($toolName, $allowed, true);
    }

    /**
     * Handle a 'done' event from within a stream envelope.
     */
    private function handleDoneFromStream(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $data = $message['data'] ?? [];
        $usage = $data['usage'] ?? null;

        $handler = $this->connectionManager->getPendingRequest($requestId);
        if (! $handler) {
            Log::debug('AI Bridge: done event received for unknown request (expected in PHP-FPM relay mode)', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
                'usage' => $usage,
            ]);

            return null;
        }

        // Apply ownership check so a bridge cannot terminate another user's
        // request by sending a spoofed done envelope.
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            Log::warning('AI Bridge: done event from wrong or unregistered user, discarding', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        // Persist the CLI session this turn ran under, so the conversation's
        // next turn resumes it instead of starting cold.
        $this->persistCliSessionId($handler, $data['cli_session_id'] ?? null);

        // Everything the bridge reported about the turn besides the token
        // counts — model, cost, duration, stop reason, permission denials.
        // Rebuilding the event from `usage` alone is the same mistake that lost
        // `tool_name` on block_start: a second place that quietly narrows what
        // arrived.
        $handler->dispatchDone($usage, array_diff_key($data, ['usage' => true]));
        $this->connectionManager->removePendingRequest($requestId);
        unset($this->recoveredRequests[$requestId]);

        return null;
    }

    /**
     * Persist the CLI session id reported by a `done` event onto its
     * conversation, so the next turn can resume the same session.
     *
     * No-ops when no usable session id was produced, or when the stream is not
     * tied to a persisted (numeric) conversation. Failures are logged, never
     * thrown — a persistence hiccup must not break stream completion.
     */
    private function persistCliSessionId(StreamHandler $handler, mixed $cliSessionId): void
    {
        if (! is_string($cliSessionId) || $cliSessionId === '') {
            return;
        }

        $conversationId = $handler->getConversationId();
        if ($conversationId === '' || ! is_numeric($conversationId)) {
            return;
        }

        try {
            Conversation::query()
                ->whereKey($conversationId)
                ->update(['cli_session_id' => $cliSessionId]);

            BridgeLog::verbose('persisted cli_session_id from done', [
                'conversation_id' => $conversationId,
                'cli_session_id' => $cliSessionId,
            ]);
        } catch (\Throwable $e) {
            BridgeLog::warning('failed to persist cli_session_id', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle an 'error' event from within a stream envelope.
     */
    private function handleErrorFromStream(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $data = $message['data'] ?? [];
        $code = $data['code'] ?? 'unknown';
        $rawMessage = $data['message'] ?? 'Unknown error';
        $errorMessage = mb_substr(strip_tags($rawMessage), 0, 500);

        $handler = $this->connectionManager->getPendingRequest($requestId);
        if (! $handler) {
            return null;
        }

        // Apply ownership check so a bridge cannot terminate another user's
        // request by sending a spoofed error envelope.
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            Log::warning('AI Bridge: error event from wrong or unregistered user, discarding', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        // session_lost is a recoverable signal, not a real error: the bridge
        // could not resume the CLI session the server asked for. Wipe the dead
        // id and silently re-issue the turn as a fresh session — the browser
        // keeps streaming on the same request_id and never sees this.
        if ($code === 'session_lost') {
            return $this->recoverLostSession($connectionId, $requestId, $handler, $errorMessage);
        }

        Log::error('AI Bridge: stream error from bridge', [
            'connection_id' => $connectionId,
            'request_id' => $requestId,
            'code' => $code,
            'message' => $errorMessage,
        ]);

        $handler->dispatchError($code, $errorMessage);
        $this->connectionManager->removePendingRequest($requestId);
        unset($this->recoveredRequests[$requestId]);

        return null;
    }

    /**
     * Recover from a lost CLI session: wipe the stored id and re-issue the
     * turn as a fresh session, transparently to the browser.
     *
     * Keeps the same request_id so the in-flight RelayStream — and the browser
     * subscribed to its channel — simply continue with the re-issued turn. The
     * pending request is intentionally NOT removed and no error is dispatched.
     *
     * Recovers a given request at most once: a fresh re-issue uses
     * cli_session_id=null and so cannot itself yield session_lost, but if it
     * somehow recurs the error is surfaced normally rather than looping.
     */
    private function recoverLostSession(
        string $connectionId,
        string $requestId,
        StreamHandler $handler,
        string $detail,
    ): ?array {
        if (isset($this->recoveredRequests[$requestId])) {
            BridgeLog::warning('session_lost recurred after recovery — surfacing the error', [
                'request_id' => $requestId,
            ]);
            $handler->dispatchError('session_lost', $detail);
            $this->connectionManager->removePendingRequest($requestId);
            unset($this->recoveredRequests[$requestId]);

            return null;
        }

        $conversationId = $handler->getConversationId();
        $conversation = is_numeric($conversationId)
            ? Conversation::query()->with('messages')->whereKey($conversationId)->first()
            : null;

        if ($conversation === null) {
            BridgeLog::error('session_lost recovery failed — conversation not found', [
                'request_id' => $requestId,
                'conversation_id' => $conversationId,
            ]);
            $handler->dispatchError('session_lost', $detail);
            $this->connectionManager->removePendingRequest($requestId);

            return null;
        }

        $this->recoveredRequests[$requestId] = true;

        // Wipe the dead session id so this retry — and every later turn —
        // starts fresh until the bridge reports a new one on `done`.
        $deadSessionId = $conversation->cli_session_id;
        $conversation->cli_session_id = null;
        $conversation->save();

        $userId = (string) $this->connectionManager->getUserIdByConnectionId($connectionId);
        $payload = $this->buildFreshAiRequest($conversation, $requestId);

        BridgeLog::info('session_lost — wiped cli_session_id, re-issuing as a fresh session', [
            'request_id' => $requestId,
            'conversation_id' => $conversation->id,
            'dead_cli_session_id' => $deadSessionId,
        ]);

        $sent = $this->connectionManager->sendToUser($userId, $payload);

        if (! $sent) {
            BridgeLog::error('session_lost recovery failed — could not re-send to bridge', [
                'request_id' => $requestId,
                'conversation_id' => $conversation->id,
            ]);
            $handler->dispatchError('bridge_send_failed', 'Could not recover the lost CLI session.');
            $this->connectionManager->removePendingRequest($requestId);
            unset($this->recoveredRequests[$requestId]);
        }

        return null;
    }

    /**
     * Rebuild a fresh-session ai_request payload for a conversation.
     *
     * Reconstructed entirely from the persisted conversation: the latest
     * message is the turn to answer, everything before it is prior history.
     * cli_session_id is null (fresh start) so the bridge seeds the new CLI
     * session from the history.
     *
     * @return array<string, mixed>
     */
    private function buildFreshAiRequest(Conversation $conversation, string $requestId): array
    {
        $history = $conversation->historyFor();
        $current = array_pop($history); // the latest (user) turn to respond to

        return AiRequestPayload::build([
            'request_id' => $requestId,
            'conversation_id' => (string) $conversation->id,
            'provider' => (string) ($conversation->provider ?? ''),
            'message' => is_array($current) ? (string) ($current['content'] ?? '') : '',
            'system_prompt' => $conversation->system_prompt ?: null,
            'options' => ['model' => $conversation->model ?: null],
            'cli_session_id' => null,
            'history' => array_values($history),
            'tools' => $this->toolRegistry->toArray($conversation->allowed_tools),
            // The reason this method goes through the shared builder rather
            // than assembling its own array: it used to, and it never learned
            // about `working_dir`. A recovered turn then ran in the bridge's
            // empty scratch directory and answered confidently about a
            // repository the CLI could not see — and the fresh session it
            // created was bound to that scratch directory, so every later turn
            // was refused `working_dir_changed` and the conversation was dead.
            'working_dir' => $conversation->working_dir,
        ]);
    }

    /**
     * Handle an 'error' message from the bridge (top-level, non-streaming).
     *
     * Applies the same ownership check as the other terminal handlers so an
     * authenticated bridge client cannot abort another user's request.
     */
    private function handleError(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $code = $message['data']['code'] ?? $message['code'] ?? $message['error'] ?? 'unknown';
        $rawMessage = $message['data']['message'] ?? $message['message'] ?? 'Unknown error';
        $errorMessage = mb_substr(strip_tags($rawMessage), 0, 500);

        // Verify the sender owns this request before dispatching the error.
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            Log::warning('AI Bridge: error message from wrong or unregistered user, discarding', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        Log::error('AI Bridge: error from bridge', [
            'connection_id' => $connectionId,
            'request_id' => $requestId,
            'code' => $code,
            'message' => $errorMessage,
        ]);

        $handler = $this->connectionManager->getPendingRequest($requestId);
        if ($handler) {
            $handler->dispatchError($code, $errorMessage);
            $this->connectionManager->removePendingRequest($requestId);
            unset($this->recoveredRequests[$requestId]);
        }

        return null;
    }

    /**
     * Handle a 'cancelled' message — bridge acknowledges cancellation.
     *
     * Dispatches a cancelled event to the StreamHandler so SSE consumers
     * receive a terminal event and don't hang waiting for done.
     *
     * Applies the same ownership check as the other terminal handlers so a
     * bridge client cannot cancel another user's request.
     */
    private function handleCancelled(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';

        // A `cancelled` for a request nobody is waiting on is an ORDINARY
        // ending, not an attack: handleUserAbort() terminates the turn locally
        // and clears the pending request the moment it sees the abort flag,
        // while the bridge's reply waits for the CLI to actually stop — so on
        // that path the reply arrives after the request is gone. (The other
        // path, BridgeStream::cancel(), deliberately keeps the request open for
        // this reply, and still reaches the ownership check below.) Logging the
        // first as a security event would put a warning in the log on every
        // cancelled turn, which is how a real one stops being noticed.
        //
        // Asked with getPendingRequest(), like every other terminal handler
        // here. getPendingRequestUserId() answers a different question than it
        // appears to: it ends in `?:`, so a request registered with a falsy
        // owner ('' or '0') reads as absent, and this would then say a live
        // turn had "already been cleaned up".
        if ($this->connectionManager->getPendingRequest($requestId) === null) {
            Log::info('AI Bridge: cancelled for a turn that has already been cleaned up', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        // SEC: Verify the sender owns this request before dispatching cancellation.
        // Mirrors the check in handleDoneFromStream() and handleErrorFromStream().
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            Log::warning('AI Bridge: cancelled message from wrong or unregistered user, discarding', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        Log::info('AI Bridge: request cancelled', [
            'connection_id' => $connectionId,
            'request_id' => $requestId,
        ]);

        $handler = $this->connectionManager->getPendingRequest($requestId);
        if ($handler) {
            // Messages delivered mid-turn that the CLI never read, so the
            // application can offer them again rather than count them as
            // answered. Only a list of ids passes.
            $pending = $message['pending_inputs'] ?? null;
            $pending = is_array($pending) && array_is_list($pending)
                ? array_values(array_filter($pending, static fn ($id): bool => is_string($id) && $id !== ''))
                : [];

            $handler->dispatchCancelled('Request was cancelled.', $pending !== [] ? ['pending_inputs' => $pending] : []);
            $this->connectionManager->removePendingRequest($requestId);
            unset($this->recoveredRequests[$requestId]);
        }

        return null;
    }

    /**
     * Verify that the connection identified by $connectionId owns the pending request.
     *
     * Returns true only when:
     *  - The connection is registered in BridgeConnectionManager (userId is non-null), AND
     *  - The pending request has a recorded owner (requestUserId is non-null), AND
     *  - Both userIds match.
     *
     * Fails CLOSED: any null value (unregistered connection or unknown request owner)
     * causes rejection rather than allowing the event through. This prevents partially-
     * authenticated connections from injecting events into any pending request.
     *
     * @param  string  $connectionId  The connection sending the event.
     * @param  string  $requestId     The request_id the event targets.
     */
    private function verifySenderOwnsRequest(string $connectionId, string $requestId): bool
    {
        $senderUserId = $this->connectionManager->getUserIdByConnectionId($connectionId);
        $requestUserId = $this->connectionManager->getPendingRequestUserId($requestId);

        // Both must be non-null and must match
        return $senderUserId !== null
            && $requestUserId !== null
            && $senderUserId === $requestUserId;
    }
}
