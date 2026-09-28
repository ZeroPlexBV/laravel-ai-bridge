<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\WebSocket;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Contracts\SendableConnection;
use Tetrix\AiBridge\Events\BridgeConnected;
use Tetrix\AiBridge\Events\BridgeDisconnected;
use Tetrix\AiBridge\Streaming\StreamHandler;

/**
 * Manages active bridge connections and pending AI requests.
 *
 * Tracks which users have an active CLI bridge connected via WebSocket.
 * In-memory state — each WebSocket server worker maintains its own instance.
 *
 * The actual WebSocket transport (send/receive) is handled by the consuming
 * app's WebSocket server (e.g. Laravel Reverb). This class provides the
 * connection bookkeeping and message routing layer.
 *
 * PHP-FPM lifecycle warning: although registered as a container singleton,
 * state does NOT persist across HTTP requests under PHP-FPM's shared-nothing
 * model — the singleton is destroyed at the end of each request. Connection
 * state only persists in long-running processes (the `ai-bridge:serve` server,
 * Octane/Swoole workers). Under PHP-FPM hasConnection() always returns false;
 * use the bridge server's /api/status endpoint instead.
 */
class BridgeConnectionManager
{
    /**
     * Map of user_id => connection metadata.
     *
     * @var array<string, array{connection_id: string, connected_at: int, connection: mixed, providers: array<int, array<string, mixed>>, token_expires_at: int|null, token_cid: int|null}>
     */
    private array $connections = [];

    /**
     * Reverse index: connection_id => user_id.
     * Makes getUserIdByConnectionId() and removeConnectionByConnectionId() O(1).
     *
     * @var array<string, string>
     */
    private array $connectionIdIndex = [];

    /**
     * Map of request_id => pending request metadata.
     *
     * @var array<string, array{stream_handler: StreamHandler, user_id: string}>
     */
    private array $pendingRequests = [];

    /**
     * Usage questions waiting on an answer, keyed by the id that was sent.
     *
     * Lives beside the pending stream requests because it is the same kind of thing: state
     * about an in-flight exchange that only the serve process can see. Each entry carries
     * the callable that finishes the HTTP response the asker is holding open, and the user
     * the question was asked on behalf of.
     *
     * The user id is not bookkeeping — it is the authorization check. Every bridge on this
     * server shares one id space here, so without it any authenticated bridge that named
     * another user's request id would answer that user's question. `pendingRequests` carries
     * `user_id` for exactly this reason and filters on it; this must too.
     *
     * @var array<string, array{user_id: string, on_answer: callable(array<string, mixed>): void}>
     */
    private array $pendingUsage = [];

    /**
     * Mid-turn messages waiting on the bridge's turn_input_ack, keyed by
     * request id and message id together (see turnInputKey()).
     *
     * The same shape and the same authorization rule as `pendingUsage`: the
     * user id is who the message was sent on behalf of, and only that user's
     * bridge may answer for it.
     *
     * @var array<string, array{user_id: string, on_answer: callable(array<string, mixed>): void}>
     */
    private array $pendingTurnInputs = [];

    /**
     * Callback for sending messages over the WebSocket connection.
     * Set by the consuming app's WebSocket server integration.
     *
     * @var \Closure|null  fn(mixed $connection, array $payload): bool
     */
    private ?\Closure $sendCallback = null;

    /**
     * Set the send callback used to transmit messages over WebSocket.
     *
     * The consuming app must set this to integrate with their WebSocket server.
     * The callback receives the connection object and the message payload array.
     *
     * @param  \Closure  $callback  fn(mixed $connection, array $payload): bool
     */
    public function setSendCallback(\Closure $callback): void
    {
        $this->sendCallback = $callback;
    }

    /**
     * Register a new bridge connection for a user.
     *
     * @param  int|string  $userId  The authenticated user ID.
     * @param  string  $connectionId  A unique identifier for this connection.
     * @param  mixed  $connection  The underlying WebSocket connection object.
     * @param  array<int, array<string, mixed>>  $providers  Provider capabilities from the bridge hello message.
     * @param  int|null  $tokenExpiresAt  Unix timestamp at which the bridge's token expires, if known.
     * @param  int|null  $tokenCid  The `cid` claim of the bridge token (the Connection row id), when present.
     *                              Identifies a managed bridge connection whose token can be refreshed.
     */
    public function addConnection(int|string $userId, string $connectionId, mixed $connection = null, array $providers = [], ?int $tokenExpiresAt = null, ?int $tokenCid = null): void
    {
        $userId = (string) $userId;

        // If the user already has a connection, disconnect it first
        if ($this->hasConnection($userId)) {
            $this->removeConnection($userId, 'replaced_by_new_connection');
        }

        $this->connections[$userId] = [
            'connection_id' => $connectionId,
            'connected_at' => time(),
            'connection' => $connection,
            'providers' => $providers,
            'token_expires_at' => $tokenExpiresAt,
            'token_cid' => $tokenCid,
        ];

        // Maintain reverse index for O(1) lookup by connection_id
        $this->connectionIdIndex[$connectionId] = $userId;

        Event::dispatch(new BridgeConnected($userId, $connectionId, time()));
    }

    /**
     * Remove a bridge connection for a user.
     *
     * @param  int|string  $userId  The user ID.
     * @param  string|null  $reason  The reason for disconnection.
     */
    public function removeConnection(int|string $userId, ?string $reason = null): void
    {
        $userId = (string) $userId;

        if (! isset($this->connections[$userId])) {
            return;
        }

        $connectionId = $this->connections[$userId]['connection_id'];

        // Fail any pending requests for this user
        $this->failPendingRequestsForUser($userId, $reason);

        unset($this->connections[$userId]);
        unset($this->connectionIdIndex[$connectionId]);

        Event::dispatch(new BridgeDisconnected($userId, $connectionId, $reason));
    }

    /**
     * Remove a bridge connection by its connection ID.
     *
     * This is useful when the consuming app's WebSocket server only knows the
     * connection ID (not the user ID), e.g. in onClose handlers.
     *
     * @param  string  $connectionId  The connection ID to look up and remove.
     * @param  string|null  $reason  The reason for disconnection.
     */
    public function removeConnectionByConnectionId(string $connectionId, ?string $reason = null): void
    {
        // O(1) lookup via reverse index
        $userId = $this->connectionIdIndex[$connectionId] ?? null;
        if ($userId !== null) {
            $this->removeConnection($userId, $reason);
        }
    }

    /**
     * Check if a user has an active bridge connection.
     */
    public function hasConnection(int|string $userId): bool
    {
        return isset($this->connections[(string) $userId]);
    }

    /**
     * Get connection metadata for a user.
     *
     * @return array{connection_id: string, connected_at: int, connection: mixed}|null
     */
    public function getConnection(int|string $userId): ?array
    {
        return $this->connections[(string) $userId] ?? null;
    }

    /**
     * Get the connection ID for a user, or null if not connected.
     */
    public function getConnectionId(int|string $userId): ?string
    {
        return $this->connections[(string) $userId]['connection_id'] ?? null;
    }

    /**
     * Look up the user ID associated with a connection ID.
     *
     * This is useful when a message arrives on a connection that was already
     * authenticated at connect time (e.g. via JWT in URL query param), and the
     * MessageHandler needs to know the user without re-authenticating.
     *
     * O(1) via reverse index.
     */
    public function getUserIdByConnectionId(string $connectionId): ?string
    {
        return $this->connectionIdIndex[$connectionId] ?? null;
    }

    /**
     * Store provider capabilities from the bridge hello message.
     *
     * For pre-authenticated connections, the connection is registered before
     * the hello arrives. This method allows updating providers after the fact.
     *
     * @param  int|string  $userId  The user ID.
     * @param  array<int, array<string, mixed>>  $providers  Provider capabilities from hello message.
     */
    public function setProviders(int|string $userId, array $providers): void
    {
        $userId = (string) $userId;

        if (isset($this->connections[$userId])) {
            $this->connections[$userId]['providers'] = $providers;
        }
    }

    /**
     * Store the directories the bridge said it will work in.
     *
     * Arrives on `hello` as `workspaces`, built entirely from what the operator
     * passed to `--allow-dir`. The server cannot add to it: sending a
     * `working_dir` outside these roots is refused by the bridge. Absent means
     * the operator allowed none — which is also what an older bridge looks
     * like, and the two are the same thing from here.
     *
     * @param  int|string  $userId  The user ID.
     * @param  array<int, array<string, mixed>>  $workspaces  Workspace refs from the hello message.
     */
    public function setWorkspaces(int|string $userId, array $workspaces): void
    {
        $userId = (string) $userId;

        if (isset($this->connections[$userId])) {
            $this->connections[$userId]['workspaces'] = $workspaces;
        }
    }

    /**
     * Get the workspaces this user's bridge advertised.
     *
     * @param  int|string  $userId  The user ID.
     * @return array<int, array<string, mixed>>  Empty when the bridge allowed none.
     */
    public function getWorkspaces(int|string $userId): array
    {
        return $this->connections[(string) $userId]['workspaces'] ?? [];
    }

    /**
     * Store the CLI isolation posture the bridge reported it adopted.
     *
     * @param  int|string  $userId  The user ID.
     * @param  array<string, mixed>  $posture  The `posture` frame's fields.
     */
    public function setPosture(int|string $userId, array $posture): void
    {
        $userId = (string) $userId;

        if (isset($this->connections[$userId])) {
            $this->connections[$userId]['posture'] = $posture;
        }
    }

    /**
     * Get the posture this user's bridge reported.
     *
     * @param  int|string  $userId  The user ID.
     * @return array<string, mixed>  Empty when the bridge never said — which
     *                               means an older bridge, not agreement.
     */
    public function getPosture(int|string $userId): array
    {
        return $this->connections[(string) $userId]['posture'] ?? [];
    }

    /**
     * Get the provider capabilities for a user's bridge connection.
     *
     * @param  int|string  $userId  The user ID.
     * @return array<int, array<string, mixed>>  Provider capabilities (may be empty if not yet received).
     */
    public function getProviders(int|string $userId): array
    {
        return $this->connections[(string) $userId]['providers'] ?? [];
    }

    /**
     * Get the Unix timestamp at which the connection's token expires, if known.
     */
    public function getTokenExpiresAt(int|string $userId): ?int
    {
        return $this->connections[(string) $userId]['token_expires_at'] ?? null;
    }

    /**
     * Get the `cid` claim (Connection row id) of the connection's token.
     *
     * Non-null only for managed CLI bridge connections — these are the
     * connections whose token the server may refresh.
     */
    public function getTokenCid(int|string $userId): ?int
    {
        return $this->connections[(string) $userId]['token_cid'] ?? null;
    }

    /**
     * Record a freshly-issued token's expiry after the connection's token has
     * been re-issued, so it is not refreshed again until it next ages out.
     */
    public function setTokenExpiresAt(int|string $userId, int $expiresAt): void
    {
        $userId = (string) $userId;

        if (isset($this->connections[$userId])) {
            $this->connections[$userId]['token_expires_at'] = $expiresAt;
        }
    }

    /**
     * Forcibly disconnect a user's bridge — sends a close frame
     * ({@see SendableConnection::CLOSE_INVALID_TOKEN}, so the CLI treats it
     * as fatal and stops reconnecting) and drops the connection. Used when a
     * connection is deleted or its token regenerated.
     *
     * @return bool  True if a connection was present and closed.
     */
    public function disconnectUser(int|string $userId, ?string $reason = 'connection_revoked'): bool
    {
        $userId = (string) $userId;

        $connection = $this->connections[$userId]['connection'] ?? null;

        if ($connection instanceof SendableConnection) {
            try {
                $connection->close(SendableConnection::CLOSE_INVALID_TOKEN);
            } catch (\Throwable) {
                // Best effort — the connection may already be gone.
            }
        }

        if (! isset($this->connections[$userId])) {
            return false;
        }

        $this->removeConnection($userId, $reason);

        return true;
    }

    /**
     * Send a message payload to a user's bridge connection.
     *
     * Supports two sending mechanisms:
     * 1. Direct BridgeConnection — if the stored connection object is a
     *    BridgeConnection instance, send JSON directly.
     * 2. Send callback — for custom transport integrations, falls back to
     *    the registered send callback.
     *
     * @param  int|string  $userId  The user ID.
     * @param  array<string, mixed>  $payload  The message to send.
     * @return bool  True if the message was sent successfully.
     */
    public function sendToUser(int|string $userId, array $payload): bool
    {
        $userId = (string) $userId;

        $connectionData = $this->connections[$userId] ?? null;
        if (! $connectionData) {
            return false;
        }

        $connection = $connectionData['connection'] ?? null;

        // Try direct send via the SendableConnection interface.
        if ($connection instanceof SendableConnection) {
            try {
                $connection->send(json_encode($payload));

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        // Fall back to send callback
        if ($this->sendCallback === null) {
            // No send callback configured — the consuming app hasn't set one up.
            // This is expected during development or when using a mock transport.
            return false;
        }

        try {
            return (bool) ($this->sendCallback)($connection, $payload);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Register a pending AI request so incoming stream events can be routed.
     *
     * @param  string  $requestId  The unique request ID.
     * @param  StreamHandler  $handler  The stream handler to dispatch events to.
     * @param  string  $userId  The user ID that owns this request.
     */
    public function registerPendingRequest(string $requestId, StreamHandler $handler, string $userId = ''): void
    {
        $this->pendingRequests[$requestId] = [
            'stream_handler' => $handler,
            'user_id' => $userId,
        ];
    }

    /**
     * Note that a usage question is out, and how to finish when it comes back.
     *
     * @param  string  $userId  The user the question is being asked on behalf of. Only that
     *                          user's bridge may answer it.
     * @param  callable(array<string, mixed>): void  $onAnswer
     */
    public function registerPendingUsage(string $requestId, string $userId, callable $onAnswer): void
    {
        $this->pendingUsage[$requestId] = [
            'user_id' => $userId,
            'on_answer' => $onAnswer,
        ];
    }

    /**
     * Hand an answer to whoever is waiting for it, and forget the question.
     *
     * Answers at most once: a second reply for the same id (a confused bridge, or a reply
     * that raced the timeout) is dropped rather than writing to a closed response.
     *
     * The answering user must be the one the question was registered for. A random request
     * id is not an authorization check — it is a secret, and secrets leak — so a frame from
     * another user's bridge is refused here rather than relied upon not to arrive.
     *
     * @param  array<string, mixed>  $answer
     */
    public function resolvePendingUsage(string $requestId, string $userId, array $answer): bool
    {
        $pending = $this->pendingUsage[$requestId] ?? null;

        if ($pending === null) {
            return false;
        }

        if ($pending['user_id'] !== $userId) {
            Log::warning('AI Bridge: usage_result for another user\'s question — refused', [
                'request_id' => $requestId,
                'answering_user' => $userId,
            ]);

            return false;
        }

        unset($this->pendingUsage[$requestId]);

        ($pending['on_answer'])($answer);

        return true;
    }

    /**
     * Give up on every usage question outstanding for a user, and say why.
     *
     * Without this a question outstanding when the bridge drops is left to the timeout: the
     * asker waits the full `usage_timeout` to be told nothing, when the answer — that the
     * machine is gone — was already known the moment the socket closed.
     */
    private function failPendingUsageForUser(string $userId): void
    {
        foreach ($this->pendingUsage as $requestId => $pending) {
            if ($pending['user_id'] !== $userId) {
                continue;
            }

            unset($this->pendingUsage[$requestId]);

            ($pending['on_answer'])(['ok' => false, 'reason' => 'not_connected']);
        }
    }

    /** Give up on a usage question (the bridge never answered). */
    public function forgetPendingUsage(string $requestId): void
    {
        unset($this->pendingUsage[$requestId]);
    }

    /**
     * Note that a mid-turn message is out, and how to finish when its ack comes back.
     *
     * @param  string  $userId  The user the message was sent on behalf of. Only that user's
     *                          bridge may acknowledge it.
     * @param  callable(array<string, mixed>): void  $onAnswer  Receives `{status, reason?}`.
     */
    public function registerPendingTurnInput(string $requestId, string $messageId, string $userId, callable $onAnswer): void
    {
        $this->pendingTurnInputs[self::turnInputKey($requestId, $messageId)] = [
            'user_id' => $userId,
            'on_answer' => $onAnswer,
        ];
    }

    /** Whether a message is already waiting on its ack. */
    public function hasPendingTurnInput(string $requestId, string $messageId): bool
    {
        return isset($this->pendingTurnInputs[self::turnInputKey($requestId, $messageId)]);
    }

    /**
     * Hand a turn_input_ack to whoever is waiting for it, and forget the message.
     *
     * At most once, and only from the user the message was sent for — the same two rules as
     * resolvePendingUsage(), for the same reasons.
     *
     * @param  array<string, mixed>  $answer
     */
    public function resolvePendingTurnInput(string $requestId, string $messageId, string $userId, array $answer): bool
    {
        $key = self::turnInputKey($requestId, $messageId);
        $pending = $this->pendingTurnInputs[$key] ?? null;

        if ($pending === null) {
            return false;
        }

        if ($pending['user_id'] !== $userId) {
            Log::warning('AI Bridge: turn_input_ack for another user\'s message — refused', [
                'request_id' => $requestId,
                'message_id' => $messageId,
                'answering_user' => $userId,
            ]);

            return false;
        }

        unset($this->pendingTurnInputs[$key]);

        ($pending['on_answer'])($answer);

        return true;
    }

    /** Give up on a mid-turn message (the bridge never answered). */
    public function forgetPendingTurnInput(string $requestId, string $messageId): void
    {
        unset($this->pendingTurnInputs[self::turnInputKey($requestId, $messageId)]);
    }

    /**
     * Answer every mid-turn message outstanding for a user whose bridge has gone.
     *
     * `no_answer`, not `turn_not_running`: the bridge never said whether it took the message,
     * and the one answer that must never be given without its word is one that makes the
     * caller send it again as a new turn.
     */
    private function failPendingTurnInputsForUser(string $userId): void
    {
        foreach ($this->pendingTurnInputs as $key => $pending) {
            if ($pending['user_id'] !== $userId) {
                continue;
            }

            unset($this->pendingTurnInputs[$key]);

            ($pending['on_answer'])(['status' => 'rejected', 'reason' => 'no_answer']);
        }
    }

    /**
     * One key for a request id and message id, unambiguous whatever either contains.
     *
     * Length-prefixed rather than JSON-encoded: both ids arrive from outside, and an encoder
     * that throws on invalid UTF-8 has no business inside the serve process's event loop.
     */
    private static function turnInputKey(string $requestId, string $messageId): string
    {
        return strlen($requestId).':'.$requestId.$messageId;
    }

    /**
     * Get the StreamHandler for a pending request.
     */
    public function getPendingRequest(string $requestId): ?StreamHandler
    {
        $entry = $this->pendingRequests[$requestId] ?? null;

        return $entry ? $entry['stream_handler'] : null;
    }

    /**
     * Remove a pending request (after completion or cancellation).
     */
    public function removePendingRequest(string $requestId): void
    {
        unset($this->pendingRequests[$requestId]);
    }

    /**
     * Get the user ID that owns a pending request.
     */
    public function getPendingRequestUserId(string $requestId): ?string
    {
        $entry = $this->pendingRequests[$requestId] ?? null;

        return $entry ? ($entry['user_id'] ?: null) : null;
    }

    /**
     * The ids of every pending request a given user owns.
     *
     * Used to answer "has anything this connection is running been stopped?"
     * without waiting for that turn to produce an event of its own.
     *
     * @return string[]
     */
    public function pendingRequestIdsForUser(int|string $userId): array
    {
        $needle = (string) $userId;

        return array_keys(array_filter(
            $this->pendingRequests,
            static fn (array $entry): bool => (string) $entry['user_id'] === $needle,
        ));
    }

    /**
     * Get all active connection user IDs.
     *
     * @return string[]
     */
    public function connectedUserIds(): array
    {
        return array_keys($this->connections);
    }

    /**
     * Get the total number of active connections.
     */
    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Fail all pending requests for a given user (e.g. on disconnect).
     *
     * Iterates all pending requests, finds those belonging to the given user,
     * dispatches an error event to their StreamHandlers, and removes them.
     */
    private function failPendingRequestsForUser(string $userId, ?string $reason = null): void
    {
        // When the disconnection reason is 'replaced_by_new_connection', the bridge
        // is reconnecting — use a more specific error code so consumers can differentiate.
        $errorCode = $reason === 'replaced_by_new_connection' ? 'bridge_reconnecting' : 'bridge_disconnected';
        $errorMessage = $reason === 'replaced_by_new_connection'
            ? 'Bridge is reconnecting — the in-flight request was interrupted. Please resend your message.'
            : 'Bridge connection lost.';

        // In-flight requests are failed immediately when a new bridge connection
        // replaces the old one — there is no grace period.
        foreach ($this->pendingRequests as $requestId => $handler) {
            if ($handler['user_id'] === $userId) {
                $handler['stream_handler']->dispatchError($errorCode, $errorMessage);
                unset($this->pendingRequests[$requestId]);
            }
        }

        // A usage question is an in-flight exchange for this user too, and it is held open
        // by an HTTP response rather than a stream handler — so it needs its own sweep.
        $this->failPendingUsageForUser($userId);
        $this->failPendingTurnInputsForUser($userId);
    }
}
