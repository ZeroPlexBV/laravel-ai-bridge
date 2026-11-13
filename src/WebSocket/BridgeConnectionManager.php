<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\WebSocket;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Contracts\SendableConnection;
use Tetrix\AiBridge\Events\BridgeConnected;
use Tetrix\AiBridge\Events\BridgeDisconnected;
use Tetrix\AiBridge\Events\TurnLostAfterDrop;
use Tetrix\AiBridge\Protocol\MessageTypes;
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
     * `last_seq` is the highest `seq` processed for the turn (0 before the first; only a
     * `reliable_delivery` bridge numbers its frames), `confirmed_seq` the highest that is also
     * in the stream store and may be confirmed with `stream_ack` (at most `last_seq`),
     * `stalled_since` the unix time the turn stopped making progress (a frame after a gap, a
     * frame that failed, a store write kept for a retry; null while it makes progress),
     * `seq_restart` that a re-issued turn may start numbering again from 1, and
     * `detached_until` the unix time a turn detached by a drop fails at unless its bridge
     * re-attaches it (null while attached).
     *
     * `reissue`, while set, is the `session_lost` re-issue of a bridge told to number it on
     * (see noteReissue()).
     *
     * @var array<string, array{stream_handler: StreamHandler, user_id: string, carry: array<string, mixed>, last_seq: int, confirmed_seq: int, stalled_since: int|null, seq_restart: bool, detached_until: int|null, reissue?: array{frame: array<string, mixed>, error_seq: int}}>
     */
    private array $pendingRequests = [];

    /**
     * Turns that ended normally with a numbered final frame, by request id =>
     * [user_id, final seq], the newest FINISHED_MEMORY entries. A bridge whose `stream_ack`
     * for that final frame was lost in a drop re-sends it after reconnecting; this lets the
     * server confirm it again instead of treating it as a turn it never knew.
     *
     * @var array<string, array{user_id: string, seq: int}>
     */
    private array $finishedRequests = [];

    private const FINISHED_MEMORY = 500;

    /**
     * Users whose `reliable_delivery` bridge dropped for a transport reason and may still come
     * back within the grace period, by user id => [unix time the wait ends, what the bridge
     * said at its last hello]. Cleared by the next hello (reattachRunningTurns()), by a
     * deliberate close, or when the period ends (expireDetachedRequests()).
     *
     * While a user is here, work for their machine waits instead of failing: see sendOrHold().
     *
     * @var array<string, array{until: int, bridge: array<string, mixed>}>
     */
    private array $awaitingReconnect = [];

    /**
     * Frames for a machine that is away (see $awaitingReconnect), by user id => key => frame
     * and what to run once it is written. Sent, oldest first, after the bridge's next hello
     * (flushHeldFrames()); dropped when the grace period ends, the waiters then being failed
     * by their own registries.
     *
     * `requires` is what the bridge must announce at hello to be sent the frame (a capability
     * such as `file_uploads`, or a hello field such as `mcp_apps`), checked against the bridge
     * that comes back; `on_refused` fails its waiter when that bridge does not.
     *
     * @var array<string, array<string, array{frame: array<string, mixed>, on_sent: (callable(): void)|null, requires?: string|null, on_refused?: (callable(): void)|null}>>
     */
    private array $heldFrames = [];

    private int $heldSequence = 0;

    /**
     * Why a connection closed, when that reason means the bridge may well come straight back.
     * Only these detach a `reliable_delivery` bridge's turns; any other reason (a revoked
     * connection, a bridge that shut down with a normal close) fails them at once.
     */
    private const TRANSPORT_DROP_REASONS = ['websocket_closed', 'websocket_error', 'replaced_by_new_connection'];

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
     * `frame` and `on_sent`, when given, let the question be put again after a short drop
     * of a `reliable_delivery` bridge (see detachPendingRequestsForUser()).
     *
     * @var array<string, array{user_id: string, on_answer: callable(array<string, mixed>): void, frame: array<string, mixed>, on_sent: (callable(): void)|null}>
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
     * `frame` and `on_sent` as for `pendingUsage`: a bridge takes the same message_id once
     * only, so sending it again after a drop cannot write it to the CLI twice.
     *
     * @var array<string, array{user_id: string, on_answer: callable(array<string, mixed>): void, frame: array<string, mixed>, on_sent: (callable(): void)|null}>
     */
    private array $pendingTurnInputs = [];

    /**
     * Other exchanges waiting on one answer from a bridge, keyed by what the answer will
     * name: `mcp:<id>` (an mcp_request), `auth:<user>:<state>` (an upstream_auth_code) and
     * `auth-start:<user>:<server>` (an upstream_auth_start). Several waiters may share a key (two
     * people pressing "Connect" at once): one answer finishes them all.
     *
     * The same authorization rule as `pendingUsage`: only the user the exchange was
     * started for may answer it, and a bridge that goes away fails it at once with
     * `gone_answer`, rather than leaving the HTTP response open until its timeout.
     *
     * @var array<string, list<array{user_id: string, on_answer: callable(array<string, mixed>): void, gone_answer: array<string, mixed>}>>
     */
    private array $pendingExchanges = [];

    /**
     * Turns failed because their user's bridge went away, by request id => user id.
     *
     * Kept briefly (the newest DROPPED_MEMORY entries) so the account a bridge replays after
     * it reconnects — a `bridge_disconnected` error naming the mid-turn messages its CLI
     * never read — can be matched to the user it belongs to after the turn itself is gone.
     *
     * @var array<string, string>
     */
    private array $droppedRequests = [];

    private const DROPPED_MEMORY = 500;

    /** @var list<\Closure(string): void> Called with the user id when a user's bridge goes away. */
    private array $userGoneListeners = [];

    /** Be told when a user's bridge goes away (after their pending requests are failed). */
    public function onUserGone(\Closure $listener): void
    {
        $this->userGoneListeners[] = $listener;
    }

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

        // Fail any pending requests for this user; a bridge that announced reliable_delivery
        // and dropped for a transport reason keeps them, detached, for the grace period (see
        // detachPendingRequestsForUser()).
        $detach = $this->keepsWorkThroughDrop($userId, $reason);

        if ($detach) {
            $this->detachPendingRequestsForUser($userId, $reason);
        } else {
            $this->failPendingRequestsForUser($userId, $reason);
            $this->stopAwaitingReconnect($userId);
        }

        unset($this->connections[$userId]);
        unset($this->connectionIdIndex[$connectionId]);

        // A machine that may come back keeps its transfers too (their bytes travel on their
        // own HTTP connections, not on this socket); they end when the grace period does.
        if (! $detach) {
            $this->notifyUserGone($userId);
        }

        Event::dispatch(new BridgeDisconnected($userId, $connectionId, $reason));
    }

    /**
     * Whether a closing connection's work is kept for its bridge to come back: only for a
     * transport-level reason (a dropped socket, a socket error, a reconnect replacing it), and
     * only for a bridge that announced `reliable_delivery` — or one that had not said hello
     * yet while an earlier drop of a reliable bridge is still waited on, so a reconnect that
     * drops before its hello does not fail the turns held for the first drop.
     */
    private function keepsWorkThroughDrop(string $userId, ?string $reason): bool
    {
        if (! in_array($reason, self::TRANSPORT_DROP_REASONS, true)) {
            return false;
        }

        if ($this->bridgeSupports($userId, 'reliable_delivery')) {
            return true;
        }

        return ! isset($this->connections[$userId]['bridge']) && isset($this->awaitingReconnect[$userId]);
    }

    private function notifyUserGone(string $userId): void
    {
        foreach ($this->userGoneListeners as $listener) {
            try {
                $listener($userId);
            } catch (\Throwable) {
                // A listener must not stop the connection being removed.
            }
        }
    }

    /** Whether this user's `reliable_delivery` bridge dropped and is still waited for. */
    public function isAwaitingReconnect(int|string $userId): bool
    {
        return isset($this->awaitingReconnect[(string) $userId]);
    }

    /** Whether work can be given to this user's machine: it is connected, or a short drop is waited out. */
    public function canReach(int|string $userId): bool
    {
        return $this->hasConnection($userId) || $this->isAwaitingReconnect($userId);
    }

    /**
     * What this user's bridge said at `hello`: from the live connection, or, while a short
     * drop is waited out, from the connection that dropped.
     *
     * @return array<string, mixed>
     */
    public function reachableBridgeInfo(int|string $userId): array
    {
        $userId = (string) $userId;

        if (isset($this->connections[$userId]['bridge'])) {
            return $this->connections[$userId]['bridge'];
        }

        return $this->awaitingReconnect[$userId]['bridge'] ?? [];
    }

    /**
     * Whether this user's connected bridge announced `$requirement` at hello: a capability
     * (bridgeSupports()), or a hello field it sends only when it can (`mcp_apps`,
     * `mcp_upstream_auth`).
     */
    public function bridgeHas(int|string $userId, string $requirement): bool
    {
        return $this->bridgeSupports($userId, $requirement)
            || is_array($this->getBridgeInfo($userId)[$requirement] ?? null);
    }

    /** bridgeSupports(), also while a short drop is waited out (see reachableBridgeInfo()). */
    public function reachableBridgeSupports(int|string $userId, string $capability): bool
    {
        return in_array($capability, $this->reachableBridgeInfo($userId)['capabilities'] ?? [], true);
    }

    /**
     * Send a frame to a user's machine now, or, while its `reliable_delivery` bridge is away
     * on a short drop, hold it and send it after the bridge's next hello.
     *
     * `$onSent` runs once the frame is written (at once, or after the reconnect). A caller
     * that holds an HTTP request open for the answer starts its clock at the request instead,
     * and drops the frame with dropHeldFrame() when that clock ends while it is still held
     * (PROTOCOL.md, "Short drops"). A frame still held when the grace period ends is dropped; whoever
     * waits on its answer is failed then by its own registry (usage, turn input, exchange) or
     * by the user-gone listeners (transfers).
     *
     * `$key` names a frame that may be held twice (a re-send of one already out), or one its
     * caller may have to drop (dropHeldFrame()): a second frame under the same key replaces
     * the first.
     *
     * `$requires` is what the frame needs the bridge to have announced (see bridgeHas()). A
     * held frame is sent after the reconnect only when the bridge that came back announced it
     * too, which an older bridge taking the machine's place may not have; otherwise it is
     * dropped unsent and `$onRefused` runs, so its waiter is failed at once (PROTOCOL.md,
     * "Short drops").
     *
     * @param  array<string, mixed>  $frame
     * @param  (callable(): void)|null  $onSent
     * @param  (callable(): void)|null  $onRefused
     * @return bool  False only when the machine is neither connected nor waited for, or the
     *               write failed.
     */
    public function sendOrHold(int|string $userId, array $frame, ?callable $onSent = null, ?string $key = null, ?string $requires = null, ?callable $onRefused = null): bool
    {
        $userId = (string) $userId;

        if (isset($this->awaitingReconnect[$userId])) {
            $this->heldFrames[$userId][$key ?? 'held:'.(++$this->heldSequence)] = [
                'frame' => $frame, 'on_sent' => $onSent, 'requires' => $requires, 'on_refused' => $onRefused,
            ];

            return true;
        }

        if (! $this->sendToUser($userId, $frame)) {
            return false;
        }

        if ($onSent !== null) {
            $onSent();
        }

        return true;
    }

    /**
     * Send what was held for a machine during its drop, now that its bridge said hello again.
     * Run after the `welcome` is written (BridgeWebSocketHandler), and on the one-second tick.
     * Stops at the first frame that cannot be written, keeping it and the rest held.
     *
     * A frame the bridge that came back cannot handle (it did not announce what the frame
     * `requires`, see sendOrHold()) is not sent: it is dropped and its waiter failed.
     *
     * @return int how many frames were sent
     */
    public function flushHeldFrames(int|string $userId): int
    {
        $userId = (string) $userId;
        $sent = 0;

        if (isset($this->awaitingReconnect[$userId]) || ! $this->hasConnection($userId)) {
            return 0;
        }

        foreach ($this->heldFrames[$userId] ?? [] as $key => $held) {
            // Dropped meanwhile by the callback of a frame before it.
            if (! isset($this->heldFrames[$userId][$key])) {
                continue;
            }

            $requires = $held['requires'] ?? null;
            if ($requires !== null && ! $this->bridgeHas($userId, $requires)) {
                unset($this->heldFrames[$userId][$key]);
                Log::info('AI Bridge: a held frame is not sent: the bridge that reconnected does not support it', [
                    'user_id' => $userId,
                    'type' => $held['frame']['type'] ?? null,
                    'requires' => $requires,
                ]);

                if (($held['on_refused'] ?? null) !== null) {
                    try {
                        ($held['on_refused'])();
                    } catch (\Throwable $e) {
                        Log::warning('AI Bridge: a refused held frame\'s callback failed', ['error' => $e->getMessage()]);
                    }
                }

                continue;
            }

            if (! $this->sendToUser($userId, $held['frame'])) {
                break;
            }

            unset($this->heldFrames[$userId][$key]);
            $sent++;

            if ($held['on_sent'] !== null) {
                try {
                    ($held['on_sent'])();
                } catch (\Throwable $e) {
                    Log::warning('AI Bridge: a held frame\'s on-sent callback failed', ['error' => $e->getMessage()]);
                }
            }
        }

        if (($this->heldFrames[$userId] ?? null) === []) {
            unset($this->heldFrames[$userId]);
        }

        return $sent;
    }

    /** flushHeldFrames() for every user that has something held. */
    public function flushAllHeldFrames(): int
    {
        $sent = 0;

        foreach (array_keys($this->heldFrames) as $userId) {
            $sent += $this->flushHeldFrames((string) $userId);
        }

        return $sent;
    }

    /** Whether a frame is held under `$key` for this user's machine (see sendOrHold()). */
    public function isHeldFrame(int|string $userId, string $key): bool
    {
        return isset($this->heldFrames[(string) $userId][$key]);
    }

    /**
     * Drop a frame held under `$key` because its caller has given up (its own timeout passed,
     * measured from its request): a held item never outlives its caller, so it is never sent
     * after the caller was told it was not delivered.
     *
     * @return bool  True when the frame was still held (never written since the drop).
     */
    public function dropHeldFrame(int|string $userId, string $key): bool
    {
        $held = $this->isHeldFrame($userId, $key);
        $this->forgetHeldFrame((string) $userId, $key);

        return $held;
    }

    /** Forget a held frame whose waiter has been answered or has given up. */
    private function forgetHeldFrame(string $userId, string $key): void
    {
        unset($this->heldFrames[$userId][$key]);

        if (($this->heldFrames[$userId] ?? null) === []) {
            unset($this->heldFrames[$userId]);
        }
    }

    /** The machine is not waited for any more: drop what was held for it. */
    private function stopAwaitingReconnect(string $userId): void
    {
        unset($this->awaitingReconnect[$userId], $this->heldFrames[$userId]);
    }

    /** The key a usage question is held under while its machine is away (see sendOrHold()). */
    public static function usageHoldKey(string $requestId): string
    {
        return 'usage:'.$requestId;
    }

    /** The key a mid-turn message is held under while its machine is away (see sendOrHold()). */
    public static function turnInputHoldKey(string $requestId, string $messageId): string
    {
        return 'turn_input:'.self::turnInputKey($requestId, $messageId);
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
     * Store what the bridge said about itself at `hello`: its release and what it supports.
     *
     * @param  int|string  $userId  The user ID.
     * @param  array<string, mixed>  $info  See MessageHandler::bridgeInfoFromHello().
     */
    public function setBridgeInfo(int|string $userId, array $info): void
    {
        $userId = (string) $userId;

        if (isset($this->connections[$userId])) {
            $this->connections[$userId]['bridge'] = $info;
        }
    }

    /**
     * What this user's bridge said about itself at `hello`.
     *
     * @return array<string, mixed>  Empty when not connected. Keys: `bridge_version`
     *                               (?string), `protocol_version` (?string),
     *                               `attachment_limits` (?array), `capabilities` (string[]),
     *                               `self_update` (bool), `mcp_apps` (?array{spec, revision}), `effort_levels` (string[]).
     */
    public function getBridgeInfo(int|string $userId): array
    {
        return $this->connections[(string) $userId]['bridge'] ?? [];
    }

    /**
     * Whether this user's connected bridge announced a capability at `hello`
     * (`turn_input`, `input_closed`, `file_uploads`, `file_downloads`, `app_backends`, `reliable_delivery`).
     */
    public function bridgeSupports(int|string $userId, string $capability): bool
    {
        return in_array($capability, $this->getBridgeInfo($userId)['capabilities'] ?? [], true);
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
     * Store the version the bridge said it runs.
     *
     * Arrives on `hello` as `bridge_version`, and normally lands here through
     * setBridgeInfo(): this writes the same field of the same record, so there
     * is one place the version lives. Null when the bridge sent none (or
     * something that was not a string).
     *
     * @param  int|string  $userId  The user ID.
     */
    public function setBridgeVersion(int|string $userId, ?string $version): void
    {
        $userId = (string) $userId;

        if (isset($this->connections[$userId])) {
            $this->connections[$userId]['bridge']['bridge_version'] = $version;
        }
    }

    /**
     * Get the version this user's bridge said it runs (getBridgeInfo()['bridge_version']).
     *
     * @param  int|string  $userId  The user ID.
     * @return string|null  Null when the bridge never said, or is not connected.
     */
    public function getBridgeVersion(int|string $userId): ?string
    {
        $version = $this->getBridgeInfo($userId)['bridge_version'] ?? null;

        return is_string($version) ? $version : null;
    }

    /**
     * Store whether the bridge will follow `welcome.desired_bridge_version`.
     *
     * Arrives on `hello` as `self_update`, true only for a bridge that will
     * actually act on it (a managed service that has not opted out). A bridge
     * older than 0.24.0 sends nothing, which is false: it cannot. Like the
     * version, it lives in the getBridgeInfo() record.
     *
     * @param  int|string  $userId  The user ID.
     */
    public function setSelfUpdate(int|string $userId, bool $selfUpdate): void
    {
        $userId = (string) $userId;

        if (isset($this->connections[$userId])) {
            $this->connections[$userId]['bridge']['self_update'] = $selfUpdate;
        }
    }

    /**
     * Whether this user's bridge said it will follow the desired version
     * (getBridgeInfo()['self_update']).
     *
     * @param  int|string  $userId  The user ID.
     */
    public function getSelfUpdate(int|string $userId): bool
    {
        return ($this->getBridgeInfo($userId)['self_update'] ?? false) === true;
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
    public function registerPendingRequest(string $requestId, StreamHandler $handler, string $userId = '', array $carry = []): void
    {
        $this->pendingRequests[$requestId] = [
            'stream_handler' => $handler,
            'user_id' => $userId,
            'carry' => $carry,
            'last_seq' => 0,
            'confirmed_seq' => 0,
            'stalled_since' => null,
            'seq_restart' => false,
            'detached_until' => null,
        ];

        // Only a turn on a `reliable_delivery` bridge keeps a failed store write; any other
        // drops it and streams on (StreamHandler::keepsFailedStoreWrites()).
        $handler->keepFailedStoreWritesWhen(fn (): bool => $this->reachableBridgeSupports($userId, 'reliable_delivery'));

        // From now on a store write this turn has to keep (BufferingSink) is owned here, so
        // it is tried again after the turn ended on whichever path (unstoredHandlers()).
        $this->storeDeferredWatched ??= new \WeakMap();
        if (! isset($this->storeDeferredWatched[$handler])) {
            $this->storeDeferredWatched[$handler] = true;
            $handler->whenStoreDeferred(function (StreamHandler $kept): void {
                $this->unstoredHandlers[spl_object_id($kept)] = $kept;
            });
        }
    }

    /**
     * Turn handlers with store writes kept to be tried again, by object id, whether their turn
     * is still pending or ended (on any path: its final frame, an unnumbered `done`, a stall, a
     * cancel, a drop). MessageHandler::tickUnconfirmedFrames() tries them again and, after
     * `stream_stall_seconds`, gives up on all but the writes that end the turn, so its buffer
     * never stays `streaming`.
     *
     * @var array<int, StreamHandler>
     */
    private array $unstoredHandlers = [];

    /**
     * Handlers whose kept writes are reported to $unstoredHandlers (weak: one registered
     * again, for a re-issue, is not watched twice, and a finished one is not held on to).
     *
     * @var \WeakMap<StreamHandler, true>|null
     */
    private ?\WeakMap $storeDeferredWatched = null;

    /** @return list<StreamHandler> handlers with store writes kept (see $unstoredHandlers) */
    public function unstoredHandlers(): array
    {
        return array_values($this->unstoredHandlers);
    }

    /** Stop tracking a handler whose kept writes are done or given up on. */
    public function forgetUnstoredHandler(StreamHandler $handler): void
    {
        unset($this->unstoredHandlers[spl_object_id($handler)]);
    }

    /** What a numbered frame is to its turn; see acceptSeq(). */
    public const SEQ_STORE = 'store';

    public const SEQ_DUPLICATE = 'duplicate';

    public const SEQ_GAP = 'gap';

    /**
     * Decide whether a numbered `stream` frame of a pending turn is to be stored. Records
     * nothing: the caller stores it with commitSeq() only once the frame was processed
     * without an error, so a frame lost to a processing error is stored when it is re-sent
     * rather than dropped as a duplicate of itself.
     *
     * Stored only when `seq` is exactly one more than the last stored for that turn (or 1
     * right after a `session_lost` re-issue, see allowSeqRestart()). At or below it is a
     * re-send the server already has (SEQ_DUPLICATE, safe to drop); above it a frame went
     * missing (SEQ_GAP): it is not stored, and the bridge re-sends in order after it
     * reconnects. An unknown request is SEQ_GAP too: nothing can be stored for it here.
     */
    public function acceptSeq(string $requestId, int $seq): string
    {
        if (! isset($this->pendingRequests[$requestId])) {
            return self::SEQ_GAP;
        }

        $entry = $this->pendingRequests[$requestId];
        $last = $entry['last_seq'] ?? 0;

        if (($entry['seq_restart'] ?? false) && ($seq === 1 || $seq === $last + 1)) {
            return self::SEQ_STORE;
        }

        if ($seq <= $last) {
            return self::SEQ_DUPLICATE;
        }

        return $seq > $last + 1 ? self::SEQ_GAP : self::SEQ_STORE;
    }

    /** A frame acceptSeq() said to store has been processed: count it as stored. */
    public function commitSeq(string $requestId, int $seq): void
    {
        if (isset($this->pendingRequests[$requestId])) {
            $this->pendingRequests[$requestId]['last_seq'] = $seq;
            $this->pendingRequests[$requestId]['seq_restart'] = false;

            // The re-issued run has said something: its ai_request arrived.
            if ($seq > ($this->pendingRequests[$requestId]['reissue']['error_seq'] ?? PHP_INT_MAX)) {
                unset($this->pendingRequests[$requestId]['reissue']);
            }
        }
    }

    /**
     * A pending turn was re-issued (`session_lost`) to a bridge told to number it on from
     * `first_seq`: keep the `ai_request` until the new run's first frame is stored, so it can
     * be sent again when the connection dropped before the bridge saw it (see
     * MessageHandler::reattachRunningTurns()).
     *
     * @param  array<string, mixed>  $frame
     */
    public function noteReissue(string $requestId, array $frame, int $errorSeq): void
    {
        if (isset($this->pendingRequests[$requestId])) {
            $this->pendingRequests[$requestId]['reissue'] = ['frame' => $frame, 'error_seq' => $errorSeq];
        }
    }

    /**
     * The re-issue of a pending turn whose new run has not sent a frame yet, or null.
     *
     * @return array{frame: array<string, mixed>, error_seq: int}|null
     */
    public function pendingReissue(string $requestId): ?array
    {
        return $this->pendingRequests[$requestId]['reissue'] ?? null;
    }

    /**
     * Hold a frame for a machine to go out with the next flush, right after the `welcome`
     * being answered now (flushHeldFrames()); under `$key`, so holding it twice sends it once.
     *
     * @param  array<string, mixed>  $frame
     */
    public function holdFrame(int|string $userId, string $key, array $frame): void
    {
        $this->heldFrames[(string) $userId][$key] = ['frame' => $frame, 'on_sent' => null, 'requires' => null, 'on_refused' => null];
    }

    /** The highest `seq` stored for a pending turn, 0 before the first (or when unknown). */
    public function lastStoredSeq(string $requestId): int
    {
        return $this->pendingRequests[$requestId]['last_seq'] ?? 0;
    }

    /**
     * Everything up to `$seq` of a pending turn is processed and in the stream store: it may
     * be confirmed to the bridge, and the turn is making progress again.
     */
    public function confirmSeq(string $requestId, int $seq): void
    {
        if (isset($this->pendingRequests[$requestId])) {
            $this->pendingRequests[$requestId]['confirmed_seq'] = $seq;
            $this->pendingRequests[$requestId]['stalled_since'] = null;
        }
    }

    /** The highest `seq` of a pending turn that may be confirmed (see confirmSeq()), 0 when none. */
    public function confirmedSeq(string $requestId): int
    {
        return $this->pendingRequests[$requestId]['confirmed_seq'] ?? 0;
    }

    /**
     * A pending turn did not make progress on a frame (one after a gap, one that failed, one
     * whose store write is kept for a retry). The first such moment is kept: the stall bound
     * (MessageHandler::tickUnconfirmedFrames()) counts from it until confirmSeq().
     */
    public function markStalled(string $requestId, ?int $now = null): void
    {
        if (isset($this->pendingRequests[$requestId]) && $this->pendingRequests[$requestId]['stalled_since'] === null) {
            $this->pendingRequests[$requestId]['stalled_since'] = $now ?? time();
        }
    }

    /**
     * The attached pending turns that have not made progress since `$since` or before. A
     * detached turn is not among them: its grace period is what bounds it.
     *
     * @return list<string>
     */
    public function stalledRequestIds(int $since): array
    {
        return array_map('strval', array_keys(array_filter(
            $this->pendingRequests,
            static fn (array $entry): bool => ($entry['stalled_since'] ?? null) !== null
                && $entry['stalled_since'] <= $since
                && ($entry['detached_until'] ?? null) === null,
        )));
    }

    /**
     * A pending turn was re-issued under the same request id (`session_lost`): its next frame
     * may number from 1 again, or carry on from the last stored.
     */
    public function allowSeqRestart(string $requestId): void
    {
        if (isset($this->pendingRequests[$requestId])) {
            $this->pendingRequests[$requestId]['seq_restart'] = true;
        }
    }

    /** Remember the final `seq` of a turn that has ended, for a re-send of it (see $finishedRequests). */
    public function rememberFinishedRequest(string $requestId, int|string $userId, int $seq): void
    {
        unset($this->finishedRequests[$requestId]);
        $this->finishedRequests[$requestId] = ['user_id' => (string) $userId, 'seq' => $seq];

        while (count($this->finishedRequests) > self::FINISHED_MEMORY) {
            unset($this->finishedRequests[array_key_first($this->finishedRequests)]);
        }
    }

    /** The final `seq` of a turn of this user that has ended, or null. */
    public function finishedRequestSeq(string $requestId, int|string $userId): ?int
    {
        $entry = $this->finishedRequests[$requestId] ?? null;

        return $entry !== null && $entry['user_id'] === (string) $userId ? $entry['seq'] : null;
    }

    /** Whether a pending turn is detached: its bridge dropped and has not re-attached it yet. */
    public function isDetached(string $requestId): bool
    {
        return ($this->pendingRequests[$requestId]['detached_until'] ?? null) !== null;
    }

    /**
     * The ids of the detached turns a user owns.
     *
     * @return string[]
     */
    public function detachedRequestIdsForUser(int|string $userId): array
    {
        $needle = (string) $userId;

        return array_map('strval', array_keys(array_filter(
            $this->pendingRequests,
            static fn (array $entry): bool => (string) $entry['user_id'] === $needle && ($entry['detached_until'] ?? null) !== null,
        )));
    }

    /** How long a detached turn waits for its bridge, in seconds (`welcome.config.reconnect_grace_seconds`). */
    public static function reconnectGraceSeconds(): int
    {
        return max(0, (int) config('ai-bridge.websocket.reconnect_grace_seconds', 120));
    }

    /**
     * A `reliable_delivery` bridge said hello again: re-attach the detached turns it lists in
     * `running_turns`, and fail at once, with `bridge_disconnected`, every detached turn of
     * this user it does not list (the machine no longer runs it).
     *
     * A listed turn that is not detached (never was, or is unknown here) is left alone.
     * Ends the wait for this machine either way (see $awaitingReconnect).
     *
     * @param  list<string>  $runningRequestIds  The `request_id`s of `hello.running_turns`.
     * @return array{reattached: list<string>, failed: list<string>}
     */
    public function reattachRunningTurns(int|string $userId, array $runningRequestIds): array
    {
        $userId = (string) $userId;
        $reattached = [];
        $failed = [];

        // The machine is back: what was held for it goes out once the welcome has
        // (flushHeldFrames()), and new work is sent straight away again.
        unset($this->awaitingReconnect[$userId]);

        foreach ($this->detachedRequestIdsForUser($userId) as $requestId) {
            if (in_array($requestId, $runningRequestIds, true)) {
                $this->pendingRequests[$requestId]['detached_until'] = null;
                // A stall seen before the drop is counted again from now: the bridge re-sends
                // what was not confirmed right after this hello.
                if ($this->pendingRequests[$requestId]['stalled_since'] !== null) {
                    $this->pendingRequests[$requestId]['stalled_since'] = time();
                }
                $reattached[] = $requestId;
            } else {
                $this->failDetachedRequest($requestId, TurnLostAfterDrop::NOT_LISTED);
                $failed[] = $requestId;
            }
        }

        if ($reattached !== [] || $failed !== []) {
            Log::info('AI Bridge: bridge reconnected; detached turns re-attached or failed', [
                'user_id' => $userId,
                'reattached' => $reattached,
                'failed' => $failed,
            ]);
        }

        return ['reattached' => $reattached, 'failed' => $failed];
    }

    /**
     * Fail every detached turn whose grace period has passed without its bridge coming back,
     * and everything else that waited for that machine (see giveUpOnReconnect()).
     *
     * Called on a one-second timer by the serve process (BridgeWebSocketServer), the only
     * process that holds these turns.
     *
     * @return int how many turns were failed
     */
    public function expireDetachedRequests(?int $now = null): int
    {
        $now ??= time();
        $failed = 0;

        foreach ($this->pendingRequests as $requestId => $entry) {
            $until = $entry['detached_until'] ?? null;

            if ($until !== null && $until <= $now) {
                $this->failDetachedRequest((string) $requestId, TurnLostAfterDrop::GRACE_EXPIRED);
                $failed++;
            }
        }

        foreach ($this->awaitingReconnect as $userId => $awaiting) {
            if ($awaiting['until'] <= $now) {
                $this->giveUpOnReconnect((string) $userId);
            }
        }

        return $failed;
    }

    /**
     * What a person is told when a turn is lost after a drop, in plain words, per reason.
     * The code stays `bridge_disconnected`; the event carries the reason for an application
     * that words its own line.
     */
    public static function lostAfterDropMessage(string $reason): string
    {
        return $reason === TurnLostAfterDrop::NOT_LISTED
            ? 'The connection to your machine dropped. It came back, but it was no longer working on this reply (it may have restarted), so the reply stopped here and the rest of it was not saved. Send your message again to continue.'
            : 'The connection to your machine dropped and it did not come back within '.self::reconnectGraceSeconds().' seconds, so the reply stopped here and the rest of it was not saved. Send your message again to continue.';
    }

    /** End a detached turn as lost: `bridge_disconnected` to its handler, then TurnLostAfterDrop. */
    private function failDetachedRequest(string $requestId, string $reason): void
    {
        $entry = $this->pendingRequests[$requestId] ?? null;

        if ($entry === null) {
            return;
        }

        unset($this->pendingRequests[$requestId]);
        $userId = (string) $entry['user_id'];
        $this->rememberDroppedRequest($requestId, $userId);

        $conversationId = '';

        try {
            $conversationId = $entry['stream_handler']->getConversationId();
            $entry['stream_handler']->dispatchError('bridge_disconnected', self::lostAfterDropMessage($reason));
        } catch (\Throwable $e) {
            Log::warning('AI Bridge: could not end a detached turn', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('AI Bridge: detached turn lost', ['request_id' => $requestId, 'user_id' => $userId, 'reason' => $reason]);

        try {
            Event::dispatch(new TurnLostAfterDrop($requestId, $userId, $conversationId, $reason));
        } catch (\Throwable $e) {
            // A listener must not stop the next turn being failed.
            Log::warning('AI Bridge: a TurnLostAfterDrop listener failed', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The per-turn fields a pending request was sent with that a `session_lost`
     * re-issue must send again (AiRequestPayload::reissueCarry()). Empty when none
     * were recorded, or the request is not pending.
     *
     * @return array<string, mixed>
     */
    public function getPendingRequestCarry(string $requestId): array
    {
        return $this->pendingRequests[$requestId]['carry'] ?? [];
    }

    /**
     * Note that a usage question is out, and how to finish when it comes back.
     *
     * @param  string  $userId  The user the question is being asked on behalf of. Only that
     *                          user's bridge may answer it.
     * @param  callable(array<string, mixed>): void  $onAnswer
     * @param  array<string, mixed>  $frame  The `usage_request` frame, to put it again after a short drop.
     * @param  (callable(): void)|null  $onSent  Run each time that frame is written (see sendOrHold()).
     */
    public function registerPendingUsage(string $requestId, string $userId, callable $onAnswer, array $frame = [], ?callable $onSent = null): void
    {
        $this->pendingUsage[$requestId] = [
            'user_id' => $userId,
            'on_answer' => $onAnswer,
            'frame' => $frame,
            'on_sent' => $onSent,
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
        $this->forgetHeldFrame($userId, self::usageHoldKey($requestId));

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
        $userId = $this->pendingUsage[$requestId]['user_id'] ?? null;
        unset($this->pendingUsage[$requestId]);

        if ($userId !== null) {
            $this->forgetHeldFrame($userId, self::usageHoldKey($requestId));
        }
    }

    /**
     * Note that a mid-turn message is out, and how to finish when its ack comes back.
     *
     * @param  string  $userId  The user the message was sent on behalf of. Only that user's
     *                          bridge may acknowledge it.
     * @param  callable(array<string, mixed>): void  $onAnswer  Receives `{status, reason?}`.
     * @param  array<string, mixed>  $frame  The `turn_input` frame, to send it again after a short drop.
     * @param  (callable(): void)|null  $onSent  Run each time that frame is written (see sendOrHold()).
     */
    public function registerPendingTurnInput(string $requestId, string $messageId, string $userId, callable $onAnswer, array $frame = [], ?callable $onSent = null): void
    {
        $this->pendingTurnInputs[self::turnInputKey($requestId, $messageId)] = [
            'user_id' => $userId,
            'on_answer' => $onAnswer,
            'frame' => $frame,
            'on_sent' => $onSent,
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
        $this->forgetHeldFrame($userId, self::turnInputHoldKey($requestId, $messageId));

        ($pending['on_answer'])($answer);

        return true;
    }

    /** Give up on a mid-turn message (the bridge never answered). */
    public function forgetPendingTurnInput(string $requestId, string $messageId): void
    {
        $key = self::turnInputKey($requestId, $messageId);
        $userId = $this->pendingTurnInputs[$key]['user_id'] ?? null;
        unset($this->pendingTurnInputs[$key]);

        if ($userId !== null) {
            $this->forgetHeldFrame($userId, self::turnInputHoldKey($requestId, $messageId));
        }
    }

    /**
     * Answer every mid-turn message outstanding for a user whose bridge has gone.
     *
     * `no_answer`, not `turn_not_running`: the bridge never said whether it took the message,
     * and the one answer that must never be given without its word is one that makes the
     * caller send it again as a new turn.
     *
     * `connection_lost` instead when a `reliable_delivery` bridge dropped and did not come
     * back within the grace period: its turn has ended too, and the caller must not take the
     * message as delivered (PROTOCOL.md, "Short drops").
     */
    private function failPendingTurnInputsForUser(string $userId, string $reason = 'no_answer'): void
    {
        foreach ($this->pendingTurnInputs as $key => $pending) {
            if ($pending['user_id'] !== $userId) {
                continue;
            }

            unset($this->pendingTurnInputs[$key]);

            ($pending['on_answer'])(['status' => 'rejected', 'reason' => $reason]);
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
     * Note that an exchange is out (an mcp_request, an upstream sign-in step), and how to
     * finish when its answer comes back. See `pendingExchanges`.
     *
     * @param  callable(array<string, mixed>): void  $onAnswer
     * @param  array<string, mixed>  $goneAnswer  What the waiter is told when the bridge drops first.
     * @return bool  True when this is the first waiter on the key, so the frame still has to be sent.
     */
    public function registerPendingExchange(string $key, string $userId, callable $onAnswer, array $goneAnswer): bool
    {
        $first = ! isset($this->pendingExchanges[$key]);

        $this->pendingExchanges[$key][] = [
            'user_id' => $userId,
            'on_answer' => $onAnswer,
            'gone_answer' => $goneAnswer,
        ];

        return $first;
    }

    /** Whether anything is waiting on this key. */
    public function hasPendingExchange(string $key): bool
    {
        return isset($this->pendingExchanges[$key]);
    }

    /**
     * Hand an answer to everyone waiting on a key for this user, and forget them.
     *
     * Waiters registered for a different user are left in place and the attempt logged:
     * an id or a state is a secret, and secrets leak, so it is no authorization.
     *
     * @param  array<string, mixed>  $answer
     * @return bool  True when at least one waiter was answered.
     */
    public function resolvePendingExchange(string $key, string $userId, array $answer): bool
    {
        $waiters = $this->pendingExchanges[$key] ?? [];
        $answered = [];
        $kept = [];

        foreach ($waiters as $waiter) {
            if ($waiter['user_id'] === $userId) {
                $answered[] = $waiter;
            } else {
                $kept[] = $waiter;
            }
        }

        if ($answered === []) {
            if ($waiters !== []) {
                Log::warning('AI Bridge: an answer for another user\'s exchange — refused', [
                    'key' => $key,
                    'answering_user' => $userId,
                ]);
            }

            return false;
        }

        if ($kept === []) {
            unset($this->pendingExchanges[$key]);
        } else {
            $this->pendingExchanges[$key] = $kept;
        }

        foreach ($answered as $waiter) {
            try {
                ($waiter['on_answer'])($answer);
            } catch (\Throwable $e) {
                Log::warning('AI Bridge: an exchange waiter failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        return true;
    }

    /** Give up on one waiter (its timeout fired); the others on the key stay. */
    public function forgetPendingExchange(string $key, callable $onAnswer): void
    {
        $waiters = array_values(array_filter(
            $this->pendingExchanges[$key] ?? [],
            static fn (array $w): bool => $w['on_answer'] !== $onAnswer,
        ));

        if ($waiters === []) {
            unset($this->pendingExchanges[$key]);
        } else {
            $this->pendingExchanges[$key] = $waiters;
        }
    }

    /** Answer every exchange outstanding for a user whose bridge has gone, with its gone answer. */
    private function failPendingExchangesForUser(string $userId): void
    {
        foreach ($this->pendingExchanges as $key => $waiters) {
            $kept = [];
            foreach ($waiters as $waiter) {
                if ($waiter['user_id'] !== $userId) {
                    $kept[] = $waiter;

                    continue;
                }
                try {
                    ($waiter['on_answer'])($waiter['gone_answer']);
                } catch (\Throwable) {
                    // One waiter must not stop the others being told.
                }
            }

            if ($kept === []) {
                unset($this->pendingExchanges[$key]);
            } else {
                $this->pendingExchanges[$key] = $kept;
            }
        }
    }

    /**
     * Store the latest `upstream_status` list the bridge sent (already checked).
     *
     * @param  list<array<string, mixed>>  $upstreams
     * @return bool  True when it differs from what was stored before.
     */
    public function setUpstreams(int|string $userId, array $upstreams): bool
    {
        $userId = (string) $userId;

        if (! isset($this->connections[$userId])) {
            return false;
        }

        $changed = ($this->connections[$userId]['upstreams'] ?? null) !== $upstreams;
        $this->connections[$userId]['upstreams'] = $upstreams;

        return $changed;
    }

    /**
     * The upstream MCP servers this user's bridge last reported (`upstream_status`).
     *
     * @return list<array<string, mixed>>  Empty when not connected or never reported.
     */
    public function getUpstreams(int|string $userId): array
    {
        return $this->connections[(string) $userId]['upstreams'] ?? [];
    }

    /**
     * The ai_bridge_connections id this user's bridge connected as, or null.
     *
     * The token's `cid` claim when it carries one (a managed connection); otherwise the
     * row whose connection_key is the user id, looked up once per connection.
     */
    public function getConnectionRowId(int|string $userId): ?int
    {
        $userId = (string) $userId;

        if (! isset($this->connections[$userId])) {
            return null;
        }

        if (array_key_exists('row_id', $this->connections[$userId])) {
            return $this->connections[$userId]['row_id'];
        }

        $rowId = $this->connections[$userId]['token_cid'] ?? null;

        if ($rowId === null) {
            try {
                $found = \Tetrix\AiBridge\Models\Connection::query()->where('connection_key', $userId)->value('id');
                $rowId = is_numeric($found) ? (int) $found : null;
            } catch (\Throwable) {
                $rowId = null;
            }
        }

        return $this->connections[$userId]['row_id'] = $rowId;
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

    /** Remember whose turn a dropped request was, for the bridge's replay (see takeDroppedRequestOwner()). */
    private function rememberDroppedRequest(string $requestId, string $userId): void
    {
        unset($this->droppedRequests[$requestId]);
        $this->droppedRequests[$requestId] = $userId;

        while (count($this->droppedRequests) > self::DROPPED_MEMORY) {
            unset($this->droppedRequests[array_key_first($this->droppedRequests)]);
        }
    }

    /**
     * Remember whose turn a request was that this process ended on its own because somebody
     * stopped it (the abort flag). The bridge's own `cancelled` follows once its CLI has
     * stopped, naming the mid-turn messages the CLI never read, and by then the request is
     * gone here: this is what lets that list still be recorded (see takeDroppedRequestOwner()).
     */
    public function rememberStoppedRequest(string $requestId, int|string $userId): void
    {
        $this->rememberDroppedRequest($requestId, (string) $userId);
    }

    /**
     * The user a turn belonged to when this process ended it without the bridge's own ending
     * (its bridge went away, or it was stopped), or null. Forgets it: the bridge sends one
     * ending per turn.
     */
    public function takeDroppedRequestOwner(string $requestId): ?string
    {
        $owner = $this->droppedRequests[$requestId] ?? null;
        unset($this->droppedRequests[$requestId]);

        return $owner;
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
     * The serve process is stopping: end every turn it is relaying.
     *
     * Nothing will relay the rest of these turns (a new serve process does not know them), so
     * each is ended here as failed, through its handler, which ends its buffer for whoever
     * reads it and clears its conversation's `streaming_request_id`. And the machine is told
     * to stop it: a turn nobody is listening to would otherwise run on, unseen, and the
     * person's next message would start a second one on the same session beside it.
     *
     * @return int how many turns were ended
     */
    public function failAllPendingRequests(string $code = 'server_restarting', string $message = 'The server restarted while this reply was running. Ask again to continue.'): int
    {
        $ended = 0;

        foreach ($this->pendingRequests as $requestId => $entry) {
            unset($this->pendingRequests[$requestId]);

            if (($entry['user_id'] ?? '') !== '') {
                try {
                    $this->sendToUser($entry['user_id'], ['type' => MessageTypes::CANCEL, 'request_id' => (string) $requestId]);
                } catch (\Throwable) {
                    // The machine may already be gone; the turn still ends here.
                }
            }

            try {
                $entry['stream_handler']->dispatchError($code, $message);
            } catch (\Throwable $e) {
                Log::warning('AI Bridge: could not end a turn on shutdown', [
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                ]);
            }

            $ended++;
        }

        return $ended;
    }

    /**
     * A `reliable_delivery` bridge dropped (a transport reason, see keepsWorkThroughDrop()):
     * keep its turns pending, detached, for `reconnect_grace_seconds` instead of failing them.
     * The machine keeps running them and holds their frames; its next `hello` re-attaches them
     * (reattachRunningTurns()), and one it does not list, or one still detached when the period
     * ends (expireDetachedRequests()), fails with `bridge_disconnected`.
     *
     * The other work for the machine waits for the reconnect as well (PROTOCOL.md, "Short
     * drops"): a usage question or mid-turn message already out is put again after the next
     * hello (both are safe to repeat), new ones are held (sendOrHold()), and transfers carry on
     * on their own connections. Only an exchange already out (an `mcp_request`, an upstream
     * sign-in step) fails at once, as before: it may have run, and running it again is not
     * safe. All of it fails when the grace period ends without the machine.
     *
     * A second drop before the reconnect's hello changes nothing that is already waiting.
     */
    private function detachPendingRequestsForUser(string $userId, ?string $reason = null): void
    {
        $until = time() + self::reconnectGraceSeconds();
        $detached = [];

        foreach ($this->pendingRequests as $requestId => $entry) {
            if ($entry['user_id'] === $userId && ($entry['detached_until'] ?? null) === null) {
                $this->pendingRequests[$requestId]['detached_until'] = $until;
                $detached[] = (string) $requestId;
            }
        }

        if ($detached !== []) {
            Log::info('AI Bridge: bridge dropped; turns detached for the grace period', [
                'user_id' => $userId,
                'reason' => $reason,
                'request_ids' => $detached,
                'detached_until' => $until,
            ]);
        }

        if (isset($this->awaitingReconnect[$userId])) {
            // Dropped again before the reconnect said hello: nothing was sent on that
            // connection (sendOrHold() kept holding), so what waits keeps waiting.
            return;
        }

        $this->awaitingReconnect[$userId] = [
            'until' => $until,
            'bridge' => $this->connections[$userId]['bridge'] ?? [],
        ];

        // An entry already held (put during an earlier drop, its write after that hello
        // failed) was never written: it keeps what it was held with, its refusal included.
        foreach ($this->pendingUsage as $requestId => $pending) {
            if ($pending['user_id'] === $userId && $pending['frame'] !== []) {
                $this->heldFrames[$userId][self::usageHoldKey((string) $requestId)] ??= ['frame' => $pending['frame'], 'on_sent' => $pending['on_sent']];
            }
        }

        foreach ($this->pendingTurnInputs as $pending) {
            if ($pending['user_id'] === $userId && $pending['frame'] !== []) {
                $frame = $pending['frame'];
                $requestId = (string) $frame['request_id'];
                $messageId = (string) $frame['message_id'];
                // Out before the drop: whether that bridge read it is unknown, so a bridge
                // that comes back without turn_input leaves it unanswered (`no_answer`).
                $this->heldFrames[$userId][self::turnInputHoldKey($requestId, $messageId)] ??= [
                    'frame' => $frame,
                    'on_sent' => $pending['on_sent'],
                    'requires' => 'turn_input',
                    'on_refused' => fn () => $this->resolvePendingTurnInput($requestId, $messageId, $userId, ['status' => 'rejected', 'reason' => 'no_answer']),
                ];
            }
        }

        // Without a frame to put again (a caller that registered none) the old rule stands.
        $this->failUnrepeatableForUser($userId);
        $this->failPendingExchangesForUser($userId);
    }

    /** Fail the usage questions and mid-turn messages of a user that cannot be sent again. */
    private function failUnrepeatableForUser(string $userId): void
    {
        foreach ($this->pendingUsage as $requestId => $pending) {
            if ($pending['user_id'] === $userId && $pending['frame'] === []) {
                unset($this->pendingUsage[$requestId]);
                ($pending['on_answer'])(['ok' => false, 'reason' => 'not_connected']);
            }
        }

        foreach ($this->pendingTurnInputs as $key => $pending) {
            if ($pending['user_id'] === $userId && $pending['frame'] === []) {
                unset($this->pendingTurnInputs[$key]);
                ($pending['on_answer'])(['status' => 'rejected', 'reason' => 'no_answer']);
            }
        }
    }

    /**
     * A short drop ended without the machine coming back in time: everything that waited for
     * it fails now, the way it would have failed at the drop.
     */
    private function giveUpOnReconnect(string $userId): void
    {
        $this->stopAwaitingReconnect($userId);

        Log::info('AI Bridge: bridge did not come back within the grace period', ['user_id' => $userId]);

        $this->failPendingUsageForUser($userId);
        $this->failPendingTurnInputsForUser($userId, 'connection_lost');
        $this->failPendingExchangesForUser($userId);
        $this->notifyUserGone($userId);
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
                $this->rememberDroppedRequest((string) $requestId, $userId);
            }
        }

        // A usage question is an in-flight exchange for this user too, and it is held open
        // by an HTTP response rather than a stream handler — so it needs its own sweep.
        $this->failPendingUsageForUser($userId);
        $this->failPendingTurnInputsForUser($userId);
        $this->failPendingExchangesForUser($userId);
    }
}
