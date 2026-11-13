<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Contracts;

/**
 * Per-turn streaming event buffer.
 *
 * Implementations hold the live event log for an in-flight AI turn keyed by
 * request_id. The serve process appends events here as they arrive from the
 * bridge; HTTP clients tail the log over SSE; resumption happens by index.
 *
 * The store survives the lifetime of the turn plus a short grace window
 * (typically 30 minutes) so a browser that loads the conversation after the
 * turn completed can still replay it. After that the entry is cleaned up.
 *
 * The contract is small on purpose — drivers may be Redis, the database,
 * an array (for tests), or anything else the application wires up via
 * {@see \Tetrix\AiBridge\Streaming\StreamStoreManager::extend()}.
 */
interface StreamStoreContract
{
    /**
     * Initialise an entry for a new turn.
     *
     * Idempotent: calling start() twice for the same request_id keeps the
     * earlier metadata and event log. Sets status to "streaming".
     *
     * @param  array<string, mixed>  $metadata  Caller-provided turn metadata,
     *   stored verbatim and surfaced via {@see status()}. Typical keys:
     *   conversation_id, started_at, provider, model.
     */
    public function start(string $requestId, array $metadata = []): void;

    /**
     * Append an event to the turn's log and return its assigned index.
     *
     * Indexes are monotonic from zero per request_id. The returned value is
     * the new event's index — clients use it as the SSE `id:` for
     * Last-Event-ID resumption.
     *
     * Throws only when the event was NOT appended: a caller keeps a write that throws and
     * does it again (BufferingSink), so a failure after the append (renewing a lifetime, say)
     * must be swallowed, or the event is appended twice.
     *
     * @param  array<string, mixed>  $data
     */
    public function appendEvent(string $requestId, string $eventName, array $data): int;

    /**
     * Read events with index > $fromIndex, in order.
     *
     * Pass -1 to read from the start (Last-Event-ID semantics: "I have
     * received up to N, give me everything after").
     *
     * @return list<array{index: int, event: string, data: array<string, mixed>}>
     */
    public function range(string $requestId, int $fromIndex = -1): array;

    /**
     * Snapshot the turn's status.
     *
     * @return array{
     *     status: string,
     *     event_count: int,
     *     last_event_index: int,
     *     metadata: array<string, mixed>,
     * }  Status is one of: "not_found", "streaming", "completed", "failed",
     *   "cancelled". For not_found the other fields are zero-valued.
     */
    public function status(string $requestId): array;

    /**
     * Signal that the turn should be aborted.
     *
     * Idempotent. The serve process polls this between events; on flip it
     * dispatches a cancelled terminal and sends a cancel frame down the
     * bridge WebSocket.
     */
    public function setAbort(string $requestId): void;

    /**
     * Whether {@see setAbort()} has been called for this turn.
     */
    public function isAborted(string $requestId): bool;

    /**
     * Mark the turn as terminated.
     *
     * @param  string  $status  One of "completed", "failed", "cancelled".
     *   Other values are accepted but unrecognised by the controllers.
     */
    public function complete(string $requestId, string $status): void;

    /**
     * Remove a turn's entry entirely (used in tests and admin cleanup).
     *
     * Normally drivers rely on TTLs for cleanup; this is the explicit form.
     */
    public function cleanup(string $requestId): void;
}
