<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;

/**
 * Attach a {@see StreamStoreContract} to a {@see StreamHandler} so every
 * dispatched event lands in the per-turn event buffer.
 *
 * Mirrors the {@see ConversationRecorder} pattern: a small static helper that
 * wires callbacks at attach-time and forgets. Attach points (in `AiBridgeManager`
 * and in `RelayStream`) match those of the recorder — the buffer is populated
 * in whichever process actually sees the events.
 *
 * Failures appending to the store are logged and swallowed. A broken buffer
 * must not break the stream itself; the worst case for a transient store
 * failure is that the browser cannot resume after a refresh — the assistant
 * row is still persisted by the recorder at terminal.
 */
final class BufferingSink
{
    /**
     * What of the provider's turn metadata a BROWSER is shown.
     *
     * An allowlist, not a denylist. `done` carries whatever the provider chose
     * to report, and a denylist forwards every future field by default —
     * including one nobody has evaluated yet. These are the documented ones
     * from PROTOCOL.md; `cli_session_id` is deliberately absent, being a
     * resumable handle the server keeps to itself.
     */
    private const PUBLIC_DONE_META = [
        'model', 'provider_version', 'stop_reason', 'cost_usd',
        'duration_ms', 'duration_api_ms', 'num_turns', 'permission_denials',
        // Why the turn ended, in the CLI's own words. A turn that arrives with
        // no text at all is the case this earns its place for: `stop_reason` is
        // null on several of those paths, so without it the only thing a chat
        // can say about an empty answer is nothing.
        'subtype',
        // What the turn spent on helpers, in the CLI's own shape. Counts only
        // — spawned, completed, failed, by type — nothing a browser should not
        // see, and the only place a chat can state a turn's helper totals.
        'subagent_stats',
        // Messages delivered mid-turn that the assistant never read, on a turn
        // that ended without reading them (a timeout, a crash). Ids only, the
        // application's own, so a chat can offer them again.
        'pending_inputs',
    ];

    /**
     * Wire a handler's callbacks into the buffered store the SSE endpoint reads.
     *
     * Every stream event a consumer can observe has to be represented here.
     * `tool_result` had no callback at all, so a result that crossed the bridge
     * intact reached the server and died in this class.
     */
    public static function attach(StreamHandler $handler, StreamStoreContract $store): void
    {
        $rid = $handler->requestId;

        // Apply the same thinking-block suppression decision the SSE wiring
        // applies, so what gets buffered matches what the UI expects to see
        // on replay.
        $suppressThinking = (bool) config('ai-bridge.streaming.suppress_thinking_blocks', true);
        $currentBlockSuppressed = false;

        $append = static function (string $event, array $data) use ($store, $rid): void {
            try {
                $store->appendEvent($rid, $event, $data);
            } catch (\Throwable $e) {
                Log::error('AI Bridge: failed to buffer stream event', [
                    'request_id' => $rid,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        $handler->onBlockStart(function (StreamEvent $event) use ($append, $suppressThinking, &$currentBlockSuppressed): void {
            $currentBlockSuppressed = $suppressThinking && ($event->data['block_type'] ?? '') === 'thinking';
            if ($currentBlockSuppressed) {
                return;
            }
            $append($event->event, $event->data);
        });

        $handler->onBlockDelta(function (StreamEvent $event) use ($append, &$currentBlockSuppressed): void {
            if ($currentBlockSuppressed) {
                return;
            }
            $append($event->event, $event->data);
        });

        $handler->onBlockStop(function (StreamEvent $event) use ($append, &$currentBlockSuppressed): void {
            if ($currentBlockSuppressed) {
                $currentBlockSuppressed = false;

                return;
            }
            $append($event->event, $event->data);
        });

        $handler->onToolCall(function (string $name, array $params, string $callId) use ($append): void {
            $append(MessageTypes::TOOL_CALL, [
                'tool_name' => $name,
                'parameters' => $params,
                'tool_call_id' => $callId,
            ]);
        });

        // Without this the browser could never see a tool result at all: the
        // bridge sends them, StreamHandler dispatches them, and the SSE buffer
        // simply had no handler, so they stopped here.
        $handler->onToolResult(function (string $toolCallId, mixed $result, ?bool $isError = null, ?string $parentToolUseId = null) use ($append): void {
            // `result` is sent even when null — dropping the key leaves the
            // browser waiting for a result that has already arrived, and the
            // call renders as still running for ever.
            $data = ['tool_call_id' => $toolCallId, 'result' => $result];
            if ($isError !== null) {
                $data['is_error'] = $isError;
            }
            // Absent for the main assistant, as on the wire.
            if ($parentToolUseId !== null) {
                $data['parent_tool_use_id'] = $parentToolUseId;
            }
            $append(MessageTypes::TOOL_RESULT, $data);
        });

        $handler->onRateLimit(function (string $provider, array $info) use ($append): void {
            $append(MessageTypes::RATE_LIMIT, ['provider' => $provider, 'info' => $info]);
        });

        $handler->onAttachment(function (array $attachment) use ($append): void {
            $append(MessageTypes::ATTACHMENT, $attachment);
        });

        // A helper's life: started, progress, heartbeat, updated, finished.
        // Buffered whole, so a browser that reconnects mid-turn replays every
        // helper's state — including one still running after the main
        // assistant's reply has ended.
        $handler->onTask(function (array $task) use ($append): void {
            $append(MessageTypes::TASK, $task);
        });

        // A message delivered mid-turn was read, and whether the main
        // assistant is working or free. Buffered in order among the blocks, so
        // a browser replaying by index places the message where the CLI read
        // it and knows, on reconnect, whether a new one would be read at once.
        $handler->onUserInput(function (array $data) use ($append): void {
            $append(MessageTypes::USER_INPUT, $data);
        });

        $handler->onMainState(function (array $data) use ($append): void {
            $append(MessageTypes::MAIN_STATE, $data);
        });

        // Terminal events both write the event AND flip the buffer status, so
        // the SSE tail and the status endpoint can tell the turn is finished.
        $handler->onDone(function (?array $usage, array $meta = []) use ($append, $store, $rid): void {
            $append(MessageTypes::DONE, ['usage' => $usage] + self::publicDoneMeta($meta));
            self::completeQuietly($store, $rid, 'completed');
        });

        $handler->onError(function (string $code, string $errorMessage) use ($append, $store, $rid): void {
            $append(MessageTypes::ERROR, ['code' => $code, 'message' => $errorMessage]);
            self::completeQuietly($store, $rid, 'failed');
        });

        $handler->onCancelled(function (string $reason, array $meta = []) use ($append, $store, $rid): void {
            $append(MessageTypes::CANCELLED, ['reason' => $reason] + self::publicCancelledMeta($meta));
            self::completeQuietly($store, $rid, 'cancelled');
        });
    }

    /**
     * Mark a stream finished, swallowing a store failure.
     *
     * Called from terminal paths where throwing would replace a finished turn
     * with an unhandled exception and leave the reader with neither.
     */
    private static function completeQuietly(StreamStoreContract $store, string $rid, string $status): void
    {
        try {
            $store->complete($rid, $status);
        } catch (\Throwable $e) {
            Log::error('AI Bridge: failed to mark stream buffer complete', [
                'request_id' => $rid,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
    /**
     * What a browser is told beside a cancellation.
     *
     * Only `pending_inputs`: the ids of messages delivered mid-turn that the
     * CLI never read, so a chat can offer them again instead of showing them
     * as answered. Absent when there were none to report.
     *
     * Public for the same reason as publicDoneMeta().
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function publicCancelledMeta(array $meta): array
    {
        $pending = $meta['pending_inputs'] ?? null;

        return is_array($pending) && $pending !== [] ? ['pending_inputs' => $pending] : [];
    }

    /**
     * Reduce the provider's turn metadata to the fields a browser may see.
     *
     * Public because AiBridgeManager's SSE path needs the same decision, and
     * two copies of an allowlist is one copy too many.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function publicDoneMeta(array $meta): array
    {
        return array_intersect_key($meta, array_flip(self::PUBLIC_DONE_META));
    }
}
