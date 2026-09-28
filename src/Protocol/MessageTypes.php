<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Protocol;

/**
 * All protocol message type constants for the AI Bridge Protocol v0.1.
 *
 * These constants define the message types exchanged between the server
 * (this package) and CLI bridge clients over WebSocket.
 *
 * This is intentionally a constants class rather than a backed enum because the
 * all() / bridgeOrigin() / serverOrigin() grouping methods require
 * runtime-composable arrays that backed enums cannot express cleanly.
 */
final class MessageTypes
{
    /** @var string[]|null Cached result of all() — populated once on first call. */
    private static ?array $allCache = null;
    // ── Connection Lifecycle ────────────────────────────────────────────

    /** Sent by bridge immediately after WebSocket upgrade. */
    public const HELLO = 'hello';

    /**
     * Sent by bridge mid-connection when its set of available provider CLIs
     * changed since the hello — e.g. a CLI was installed or removed while the
     * bridge stayed connected. The server refreshes the connection's
     * advertised providers from it.
     */
    public const PROVIDERS_UPDATE = 'providers_update';

    /** Sent by server in response to a valid hello. */
    public const WELCOME = 'welcome';

    /** Sent by server when hello validation fails. */
    public const CONNECTION_ERROR = 'connection_error';

    /**
     * Sent by server to hand the bridge a fresh connection token.
     *
     * Bridge tokens are long-lived but still expire; the server re-issues one
     * once the current token is past half its life so a long-running bridge
     * never lapses. The bridge adopts the new token for future reconnects.
     */
    public const TOKEN_REFRESH = 'token_refresh';

    // ── Heartbeat ───────────────────────────────────────────────────────

    /** Sent by bridge to check server liveness. */
    public const PING = 'ping';

    /** Sent by server in response to ping. */
    public const PONG = 'pong';

    // ── AI Request / Response ───────────────────────────────────────────

    /** Sent by server to request an AI completion from the bridge. */
    public const AI_REQUEST = 'ai_request';

    /** Sent by bridge to acknowledge receipt of an ai_request. */
    public const AI_REQUEST_ACK = 'ai_request_ack';

    /**
     * Sent by server to replay conversation history after a lost session.
     *
     * TODO: server-side implementation is not yet complete — no code sends this
     * message, and reconnected bridges currently start fresh rather than
     * replaying history.
     */
    public const SESSION_RESET = 'session_reset';

    // ── Streaming ───────────────────────────────────────────────────────

    /**
     * The envelope type for all streaming events from bridge to server.
     *
     * Streaming events are wrapped in: { "type": "stream", "event": "<event_type>", ... }
     * The individual event types (block_start, block_delta, etc.) appear in the "event" field.
     */
    public const STREAM = 'stream';

    /** Stream event: a new content block has started. */
    public const BLOCK_START = 'block_start';

    /** Stream event: a delta (chunk) within a content block. */
    public const BLOCK_DELTA = 'block_delta';

    /** Stream event: a content block has ended. */
    public const BLOCK_STOP = 'block_stop';

    /** Stream event: the AI wants to call a tool. */
    public const TOOL_CALL = 'tool_call';

    /**
     * Stream event (bridge → server): acknowledges receipt of a tool result.
     *
     * This is a streaming event sent by the bridge inside the "stream" envelope
     * to confirm it received a tool_resolve message and passed the result to the CLI.
     */
    public const TOOL_RESULT = 'tool_result';

    /** Provider rate-limit status. Informational; the turn continues. */
    public const RATE_LIMIT = 'rate_limit';

    /**
     * Stream event: a helper (sub-agent, or a background shell command) the
     * CLI runs for the main assistant started, progressed, is still alive, or
     * ended. Informational and non-terminal, like rate_limit.
     *
     * Its `tool_use_id` is the `tool_call_id` of the spawning call and the
     * `parent_tool_use_id` on the helper's own blocks and results. A helper is
     * finished only when a `finished` phase says so — never when its spawning
     * call's tool_result arrives, which for a background helper is at once.
     */
    public const TASK = 'task';

    /**
     * A file the assistant produced and chose to hand back.
     *
     * Emitted by the bridge after it has uploaded the file to
     * `POST /ai-bridge/attachments` and the app's store returned an id. The
     * event carries that id, so the UI renders the file from the app's own
     * attachment store rather than from anything the bridge holds.
     */
    public const ATTACHMENT = 'attachment';

    /**
     * The CLI isolation posture the bridge actually adopted.
     *
     * The server asks for one in `welcome`, and the bridge may decline it:
     * `workspace` and `native` are gated on flags the bridge operator passes,
     * and a bridge started without them runs `isolated` instead. Without this
     * frame that refusal is visible only in a log on someone else's machine —
     * so an operator who forgot `--allow-native` sees a connection that looks
     * healthy in every screen while the assistant silently has no tools.
     *
     * Sent once per handshake whether or not it matches the request. Absence
     * means an older bridge, not agreement.
     */
    public const POSTURE = 'posture';

    /**
     * Server → bridge: asks what is left of the subscription its CLI is signed in as.
     *
     * Carries nothing but an `id`. The bridge already knows which CLI it runs and holds the
     * only credential that could answer, so there is nothing for the server to tell it, and
     * the server deliberately never sees that credential.
     *
     * Answered by exactly one USAGE_RESULT echoing the id.
     */
    public const USAGE_REQUEST = 'usage_request';

    /**
     * Bridge → server: the allowance figures, or why there are none.
     *
     * The bridge answers EVERY usage_request, including one it cannot help with: an
     * unanswered request is indistinguishable from a bridge too old to know the frame, and
     * the server could only tell them apart by waiting out a timeout.
     *
     * Absence of this type in a bridge's vocabulary means an older bridge, not a refusal.
     */
    public const USAGE_RESULT = 'usage_result';

    /**
     * Server → bridge: sends the result of a tool execution back to the bridge.
     *
     * This is a top-level message type (NOT inside a stream envelope).
     * The bridge receives this, passes the result to the CLI, and sends back
     * a stream event with event: "tool_result" to acknowledge.
     */
    public const TOOL_RESOLVE = 'tool_resolve';

    /**
     * Server → bridge: a tool execution failed.
     *
     * Sent when the server fails to execute a tool the AI requested.
     */
    public const TOOL_ERROR = 'tool_error';

    /** Stream event: the entire AI response is complete. */
    public const DONE = 'done';

    /** Sent by bridge: an error occurred during AI processing. */
    public const ERROR = 'error';

    // ── Control ─────────────────────────────────────────────────────────

    /** Sent by server to cancel an in-progress AI request. */
    public const CANCEL = 'cancel';

    /** Sent by bridge to acknowledge a cancellation. */
    public const CANCELLED = 'cancelled';

    /**
     * All valid message types as an array, useful for validation.
     *
     * Result is cached in a static property so the array is not reconstructed
     * on every incoming WebSocket message.
     *
     * @return string[]
     */
    public static function all(): array
    {
        if (self::$allCache !== null) {
            return self::$allCache;
        }

        return self::$allCache = [
            self::HELLO,
            self::PROVIDERS_UPDATE,
            self::WELCOME,
            self::CONNECTION_ERROR,
            self::TOKEN_REFRESH,
            self::PING,
            self::PONG,
            self::AI_REQUEST,
            self::AI_REQUEST_ACK,
            self::SESSION_RESET,
            self::STREAM,
            self::BLOCK_START,
            self::BLOCK_DELTA,
            self::BLOCK_STOP,
            self::TOOL_CALL,
            self::TOOL_RESULT,
            self::RATE_LIMIT,
            self::TASK,
            self::ATTACHMENT,
            self::POSTURE,
            self::USAGE_REQUEST,
            self::USAGE_RESULT,
            self::TOOL_RESOLVE,
            self::TOOL_ERROR,
            self::DONE,
            self::ERROR,
            self::CANCEL,
            self::CANCELLED,
        ];
    }

    /**
     * Check if a given type is a valid message type.
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    /**
     * Message types that are sent by the bridge (client → server).
     *
     * Per PROTOCOL.md:
     * - hello: after WebSocket connects
     * - ping: every heartbeat interval (bridge pings, server pongs)
     * - ai_request_ack: after receiving an ai_request
     * - stream: envelope for all streaming events (block_start, block_delta, etc.)
     * - tool_call: AI wants to invoke a server-side tool
     * - error: request-level error (non-streaming)
     * - cancelled: acknowledges cancellation
     *
     * @return string[]
     */
    public static function bridgeOrigin(): array
    {
        return [
            self::HELLO,
            self::PROVIDERS_UPDATE,
            self::POSTURE,
            self::USAGE_RESULT,
            self::PING,
            self::AI_REQUEST_ACK,
            self::STREAM,
            self::TOOL_CALL,
            self::ERROR,
            self::CANCELLED,
        ];
    }

    /**
     * Message types that are sent by the server (server → bridge).
     *
     * Per PROTOCOL.md:
     * - welcome: after receiving hello
     * - pong: after receiving ping
     * - ai_request: new AI request for a conversation
     * - session_reset: replay conversation after lost session
     * - tool_resolve: returning tool execution result to bridge
     * - tool_error: tool execution failed
     * - cancel: cancel an in-progress request
     *
     * @return string[]
     */
    public static function serverOrigin(): array
    {
        return [
            self::WELCOME,
            self::CONNECTION_ERROR,
            self::TOKEN_REFRESH,
            self::PONG,
            self::AI_REQUEST,
            self::SESSION_RESET,
            self::TOOL_RESOLVE,
            self::TOOL_ERROR,
            self::USAGE_REQUEST,
            self::CANCEL,
        ];
    }
}
