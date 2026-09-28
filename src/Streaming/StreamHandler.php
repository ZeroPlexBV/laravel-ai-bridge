<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Closure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Enums\TerminatedBy;
use Tetrix\AiBridge\Events\StreamCompleted;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;

/**
 * The unified streaming interface for AI responses.
 *
 * StreamHandler is the main class consuming applications interact with.
 * It provides a consistent callback-based API regardless of whether the
 * AI response comes from a CLI bridge, BYOK, or managed provider.
 *
 * Usage:
 *   $stream = AiBridge::stream($conversationId, $message);
 *   $stream->onBlockStart(fn (StreamEvent $e) => ...);
 *   $stream->onBlockDelta(fn (StreamEvent $e) => ...);
 *   $stream->onBlockStop(fn (StreamEvent $e) => ...);
 *   $stream->onToolCall(fn (string $name, array $params, string $callId) => ...);
 *   $stream->onToolResult(fn (string $callId, mixed $result, ?bool $isError, ?string $parentToolUseId) => ...);
 *   $stream->onTask(fn (array $task) => ...);
 *   $stream->onDone(fn (?array $usage) => ...);
 *   $stream->onError(fn (string $code, string $message) => ...);
 *   $stream->start();
 */
class StreamHandler
{
    /** @var Closure[] */
    private array $blockStartCallbacks = [];

    /** @var Closure[] */
    private array $blockDeltaCallbacks = [];

    /** @var Closure[] */
    private array $blockStopCallbacks = [];

    /** @var Closure[] */
    private array $toolCallCallbacks = [];

    /** @var Closure[] */
    private array $doneCallbacks = [];

    /** @var Closure[] */
    private array $errorCallbacks = [];

    /** @var Closure[] */
    private array $cancelledCallbacks = [];

    /** @var Closure[] */
    private array $toolResultCallbacks = [];

    /** @var array<int, Closure> */
    private array $rateLimitCallbacks = [];

    /** @var array<int, Closure> */
    private array $taskCallbacks = [];

    /**
     * Partially received tool results, keyed by tool_call_id.
     *
     * A result larger than one frame crosses in chunks. They are reassembled
     * HERE, at the first thing to touch the wire, so everything downstream —
     * the recorder, the buffering sink, the browser component — keeps seeing a
     * single complete result and needs no knowledge of chunking at all.
     *
     * @var array<string, array{parts: list<string>, bytes: int, next: int, is_error: bool|null, parent: string|null, incomplete: bool}>
     */
    private array $toolResultChunks = [];

    /**
     * The largest result this will assemble, matching the bridge's own ceiling.
     *
     * Chunks are held in memory until the result completes, so without a limit
     * a stream could ask this process for an unbounded allocation. The bridge
     * stops sending at the same number; this is the belt to that braces, since
     * a server must not depend on a client to bound its memory.
     */
    private const MAX_TOTAL_RESULT_BYTES = 16 * 1024 * 1024;

    /**
     * The same ceiling, applied across ALL open buffers at once.
     *
     * The per-result limit above bounds one buffer. The number of buffers is
     * chosen by the sender, which picks the `tool_call_id` each one is keyed
     * by, so on its own that limit bounds nothing: a thousand ids carrying one
     * unfinished chunk each is a thousand buffers, and none of them individually
     * near its ceiling.
     */
    private const MAX_OPEN_RESULT_BYTES = 32 * 1024 * 1024;

    /**
     * How many results may be assembling at once.
     *
     * Parallel tool calls are real, but a handful — this is far above any
     * genuine turn and far below the number needed to hurt.
     */
    private const MAX_OPEN_RESULT_BUFFERS = 64;

    /** Bytes held across every open buffer, so the aggregate can be checked. */
    private int $toolResultBytes = 0;

    /**
     * Everything the provider reported about the last turn besides usage —
     * model, cost, duration, stop reason, permission denials.
     *
     * @var array<string, mixed>
     */
    private array $lastDoneMeta = [];

    /**
     * The token counts from the last `done`, including one that arrived after
     * an error terminal and so was never dispatched.
     *
     * @var array<string, mixed>|null
     */
    private ?array $lastDoneUsage = null;

    /** @var Closure[] */
    private array $attachmentCallbacks = [];

    private bool $cancelled = false;

    /**
     * Block type for each open block, keyed by block_index.
     *
     * Per PROTOCOL.md only `block_start` carries `block_type`; `block_delta`
     * and `block_stop` identify their block by `block_index` alone. We record
     * each block's type from its block_start so deltas/stops can be resolved.
     *
     * @var array<int, BlockType>
     */
    private array $blockTypes = [];

    /**
     * Whether a terminal event (done or error) has already been dispatched.
     * Prevents double-dispatch if both done and error fire.
     */
    private bool $terminated = false;

    /** The resolved provider mode, for accurate StreamCompleted reporting. */
    private ?ProviderMode $mode = null;

    /** Track the start time for duration reporting in StreamCompleted. */
    private float $startedAt = 0;

    /** The conversation ID, set when start() is called for StreamCompleted dispatch. */
    private string $conversationId = '';

    /**
     * Per-request memo of the conversation's tool allowlist (null = all tools),
     * resolved once on the first tool call so the runtime guard doesn't re-query
     * the DB for every tool call on the shared event loop. See `cacheAllowedTools`.
     */
    private bool $allowedToolsResolved = false;

    /** @var list<string>|null */
    private ?array $allowedTools = null;

    public readonly string $requestId;

    public function __construct(
        private readonly StreamableProvider $provider,
        ?string $requestId = null,
    ) {
        $this->requestId = $requestId ?? Str::uuid()->toString();
    }

    /**
     * Register a callback for when a content block starts.
     *
     * The callback receives a StreamEvent with block_type and block_index in data.
     */
    public function onBlockStart(Closure $callback): static
    {
        $this->blockStartCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for content deltas within a block.
     *
     * The callback receives a StreamEvent with block_type, block_index, and content in data.
     */
    public function onBlockDelta(Closure $callback): static
    {
        $this->blockDeltaCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for when a content block ends.
     *
     * The callback receives a StreamEvent with block_type and block_index in data.
     */
    public function onBlockStop(Closure $callback): static
    {
        $this->blockStopCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for tool calls from the AI.
     *
     * The callback receives: string $toolName, array $params, string $callId.
     */
    public function onToolCall(Closure $callback): static
    {
        $this->toolCallCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for when the stream completes.
     *
     * The callback receives: ?array $usage (token counts, if available).
     */
    public function onDone(Closure $callback): static
    {
        $this->doneCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for errors during streaming.
     *
     * The callback receives: string $code, string $message.
     */
    public function onError(Closure $callback): static
    {
        $this->errorCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for when the stream is cancelled.
     *
     * The callback receives: string $reason.
     */
    public function onCancelled(Closure $callback): static
    {
        $this->cancelledCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for tool_result events from the bridge.
     *
     * The callback receives: string $toolCallId, mixed $result, ?bool $isError,
     * ?string $parentToolUseId. $isError is null when the provider did not
     * report a status — never assume null means success. $parentToolUseId is
     * the spawning call when the call was a helper's (sub-agent's), and null
     * for the main assistant's own calls. A callback declaring only the first
     * three parameters keeps working: PHP drops the extra argument.
     */
    public function onToolResult(Closure $callback): static
    {
        $this->toolResultCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for rate_limit events from the bridge.
     *
     * The callback receives: string $provider, array $info. Informational and
     * non-terminal — the turn continues. The shape of $info is the provider's
     * own and is passed through unchanged.
     */
    public function onRateLimit(Closure $callback): static
    {
        $this->rateLimitCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for task events — the life of a helper (sub-agent,
     * or background shell command) the CLI runs for the main assistant.
     *
     * The callback receives the event's data array as the bridge sent it:
     * `phase` (started, progress, heartbeat, updated, finished), `task_id`,
     * usually `tool_use_id`, and whatever else that phase carries. Passed
     * through whole, so fields a newer bridge adds arrive too. Informational
     * and non-terminal. A helper is finished only when a `finished` phase says
     * so; a background helper keeps reporting after the main assistant's reply.
     */
    public function onTask(Closure $callback): static
    {
        $this->taskCallbacks[] = $callback;

        return $this;
    }

    /**
     * What the provider reported about the last completed turn besides usage.
     *
     * @return array<string, mixed>
     */
    public function lastDoneMeta(): array
    {
        // Without the session handle. It is how a turn is resumed and it is
        // persisted from the frame itself, not from here, so nothing needs it
        // in this array — and the README points a host app straight at this
        // accessor, which should not hand back a resumable handle nobody asked
        // for. `BufferingSink::publicDoneMeta()` withholds it from the browser
        // for the same reason; this is the other place it is handed out.
        return array_diff_key($this->lastDoneMeta, ['cli_session_id' => true]);
    }

    /**
     * What the turn cost, whether it succeeded or failed.
     *
     * @return array<string, mixed>|null
     */
    public function lastDoneUsage(): ?array
    {
        return $this->lastDoneUsage;
    }

    /**
     * Register a callback for attachment events — a file the assistant sent back.
     *
     * The callback receives the event's data array: `id`, `name`, `mime_type`,
     * `size`, and `description` when the model gave one. The `id` is the one
     * the app's own `storeAttachmentUsing()` returned, so the UI can render
     * the file straight from the app's attachment store.
     */
    public function onAttachment(Closure $callback): static
    {
        $this->attachmentCallbacks[] = $callback;

        return $this;
    }

    /**
     * Start the stream. This method blocks until the stream completes, errors, or is cancelled.
     */
    public function start(): void
    {
        $this->cancelled = false;
        $this->terminated = false;
        $this->blockTypes = [];
        $this->startedAt = microtime(true);
        $this->provider->start();
    }

    /**
     * Cancel the active stream.
     */
    public function cancel(): void
    {
        $this->cancelled = true;
        $this->provider->cancel();
    }

    /**
     * Check if the stream has been cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Set the conversation ID (used for StreamCompleted event dispatch).
     *
     * @internal Set by the provider or manager.
     */
    public function setConversationId(string $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    /**
     * Get the conversation ID this stream belongs to ('' if unset).
     *
     * Used server-side to persist the CLI session id from a `done` event and
     * to rebuild the request when recovering a lost session.
     */
    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    /** Whether this request's tool allowlist has been resolved yet (see cacheAllowedTools). */
    public function hasResolvedAllowedTools(): bool
    {
        return $this->allowedToolsResolved;
    }

    /**
     * Memoize this request's tool allowlist (null = all tools). Set once per
     * request so the runtime tool guard resolves the conversation's allowlist a
     * single time rather than re-querying the DB on every tool call.
     *
     * @param  list<string>|null  $allowedTools
     */
    public function cacheAllowedTools(?array $allowedTools): void
    {
        $this->allowedTools = $allowedTools;
        $this->allowedToolsResolved = true;
    }

    /** @return list<string>|null */
    public function getAllowedTools(): ?array
    {
        return $this->allowedTools;
    }

    /**
     * Set the provider mode (used for StreamCompleted event dispatch).
     *
     * @internal Set by AiBridgeManager after mode resolution.
     */
    public function setMode(ProviderMode $mode): void
    {
        $this->mode = $mode;
    }

    // ── Internal dispatch methods (called by provider implementations) ──

    /**
     * Dispatch a block_start event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchBlockStart(
        BlockType $blockType,
        int $blockIndex,
        ?string $toolName = null,
        ?string $toolCallId = null,
        ?string $parentToolUseId = null,
    ): void {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        $event = StreamEvent::blockStart($this->requestId, $blockType, $blockIndex, $toolName, $toolCallId, $parentToolUseId);
        $this->dispatchCallbacks($this->blockStartCallbacks, [$event], 'blockStart');
    }

    /**
     * Dispatch a block_delta event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchBlockDelta(BlockType $blockType, int $blockIndex, string $content): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        $event = StreamEvent::blockDelta($this->requestId, $blockType, $blockIndex, $content);
        $this->dispatchCallbacks($this->blockDeltaCallbacks, [$event], 'blockDelta');
    }

    /**
     * Dispatch a block_stop event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchBlockStop(BlockType $blockType, int $blockIndex): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        $event = StreamEvent::blockStop($this->requestId, $blockType, $blockIndex);
        $this->dispatchCallbacks($this->blockStopCallbacks, [$event], 'blockStop');
    }

    /**
     * Dispatch a tool_call event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchToolCall(string $toolName, array $params, string $callId): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        $this->dispatchCallbacks($this->toolCallCallbacks, [$toolName, $params, $callId], 'toolCall');
    }

    /**
     * Dispatch a rate_limit event to all registered callbacks.
     *
     * Non-terminal on purpose: a rate-limit notice is the provider reporting
     * how much of a window is spent, not a failure. Treating it as terminal
     * would abort a turn that is still running perfectly well.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchRateLimit(string $provider, array $info): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        $this->dispatchCallbacks($this->rateLimitCallbacks, [$provider, $info], 'rateLimit');
    }

    /**
     * Dispatch a task event to all registered callbacks.
     *
     * Non-terminal, like rate_limit. $task is forwarded untouched; nothing
     * here whitelists its fields.
     *
     * @param  array<string, mixed>  $task
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchTask(array $task): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        $this->dispatchCallbacks($this->taskCallbacks, [$task], 'task');
    }

    /**
     * Dispatch a done event to all registered callbacks.
     *
     * Also dispatches the StreamCompleted Laravel event for logging/analytics.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchDone(?array $usage = null, array $meta = []): void
    {
        if ($this->cancelled || $this->terminated) {
            // A turn that ends in an ERROR still gets a trailing `done`, and it
            // carries what the turn cost — often more than a turn that
            // succeeded. Returning here threw all of it away, so the cost of
            // exactly the turns worth investigating was the cost nobody could
            // see. It must not dispatch a second terminal, but there is no
            // reason to discard the numbers: they are recorded for
            // `lastDoneMeta()` and `lastDoneUsage()` to hand back.
            if ($this->terminated && ! $this->cancelled) {
                $this->lastDoneMeta = $meta;
                $this->lastDoneUsage = $usage;
            }

            return;
        }

        // Before the flag goes up: dispatchToolResult refuses to run once the
        // stream is terminated, so a partial result flushed after this line
        // would be dropped by the very guard meant to protect it.
        $this->flushPendingToolResults();

        $this->terminated = true;

        $this->lastDoneMeta = $meta;
        $this->lastDoneUsage = $usage;

        // $meta is passed as a second argument. PHP allows extra arguments to a
        // userland closure, so callbacks written against the one-argument form
        // keep working untouched.
        $this->dispatchCallbacks($this->doneCallbacks, [$usage, $meta], 'done');

        $this->dispatchStreamCompleted(true, $usage, null, TerminatedBy::Success);

        $this->provider->markCompleted();
    }

    /**
     * Dispatch an error event to all registered callbacks.
     *
     * Also dispatches the StreamCompleted Laravel event (with success=false).
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchError(string $code, string $message): void
    {
        if ($this->terminated) {
            return;
        }

        // Same ordering as `done`: flush while dispatch is still permitted. An
        // error is exactly when a half-delivered result is worth keeping.
        $this->flushPendingToolResults();

        $this->terminated = true;

        $this->dispatchCallbacks($this->errorCallbacks, [$code, $message], 'error');

        $this->dispatchStreamCompleted(false, null, "{$code}: {$message}", TerminatedBy::Error);

        $this->provider->markCompleted();
    }

    /**
     * Take one tool_result frame off the wire, reassembling it if it is a chunk.
     *
     * A frame with no `chunk_index` is a whole result and goes straight through
     * — the shape every result had before chunking existed, and the shape every
     * result under the per-frame budget still has.
     *
     * @param  array<string, mixed>  $data
     */
    private function receiveToolResult(array $data): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        $toolCallId = (string) ($data['tool_call_id'] ?? $data['call_id'] ?? '');
        $isError = is_bool($data['is_error'] ?? null) ? $data['is_error'] : null;
        $parent = self::parentOf($data);

        if (! is_int($data['chunk_index'] ?? null)) {
            // An unchunked result supersedes anything half-assembled for the
            // same id. Left in place the buffer never finalises, and its bytes
            // stay charged against the turn's ceiling until the terminal —
            // starving every later result on this connection.
            //
            // Only when the frame actually CARRIES a result, though. A frame
            // with no `result` key at all was throwing away everything already
            // buffered and dispatching null in its place: the content gone, and
            // nothing saying so.
            // Marked partial BEFORE it is taken: its final chunk never arrived,
            // and `takeToolResult` only appends the marker for a buffer already
            // flagged. Without this the half-assembled content is handed over as
            // if it were the whole result.
            if (isset($this->toolResultChunks[$toolCallId])) {
                $this->toolResultChunks[$toolCallId]['incomplete'] = true;
            }

            $superseded = $this->takeToolResult($toolCallId);
            if (! array_key_exists('result', $data) && $superseded !== null) {
                $this->dispatchToolResult($toolCallId, $superseded['result'], $superseded['is_error'], $parent ?? $superseded['parent']);

                return;
            }

            $this->dispatchToolResult($toolCallId, $data['result'] ?? null, $isError, $parent ?? $superseded['parent'] ?? null);

            return;
        }

        $index = $data['chunk_index'];
        $final = ($data['final'] ?? false) === true;
        $piece = is_string($data['result'] ?? null) ? $data['result'] : '';

        // The bridge names how much IT dropped at its own ceiling. Worked out
        // before either path below can return, because both of them can carry a
        // final chunk and this belongs on whichever one does.
        $droppedByBridge = $data['truncated_bytes'] ?? null;
        $droppedNote = is_int($droppedByBridge) && $droppedByBridge > 0
            ? "\n…[the bridge dropped a further {$droppedByBridge} bytes at its own ceiling]"
            : '';

        $buffer = $this->toolResultChunks[$toolCallId] ?? null;

        // A whole result that happens to be labelled as a chunk — index 0 and
        // final in one frame — needs no buffer, so no buffering limit has any
        // business refusing it. It used to be dropped outright once 64 buffers
        // were open: no callback, no result on the block, and a chat drawing
        // that call as still running for ever.
        if ($buffer === null && $final && $index === 0) {
            // Still bounded. Skipping the buffer is the point of this path;
            // skipping the ceiling with it would quietly remove the bound —
            // measured at 24 MB through a 16 MB limit.
            if (strlen($piece) > self::MAX_TOTAL_RESULT_BYTES) {
                $piece = mb_strcut($piece, 0, self::MAX_TOTAL_RESULT_BYTES, 'UTF-8')
                    ."\n…[truncated by the server: this result alone exceeded the memory one result may use]";
            }

            $this->dispatchToolResult($toolCallId, $piece.$droppedNote, $isError, $parent);

            return;
        }

        if ($buffer === null) {
            if (count($this->toolResultChunks) >= self::MAX_OPEN_RESULT_BUFFERS) {
                Log::warning('AI Bridge: refusing a new tool result buffer', [
                    'request_id' => $this->requestId,
                    'open' => count($this->toolResultChunks),
                ]);

                return;
            }

            $buffer = ['parts' => [], 'bytes' => 0, 'next' => 0, 'is_error' => null, 'parent' => null, 'refused' => false, 'incomplete' => false, 'dropped_note' => ''];
        }

        // The verdict rides on every chunk, so the last one seen wins and a
        // result cut short still carries whether it was a failure.
        if ($isError !== null) {
            $buffer['is_error'] = $isError;
        }

        // So does the helper the call belongs to. Kept across reassembly for
        // the same reason: a result flushed partial at a terminal must still
        // say whose it was, or a helper's output lands on the main assistant.
        if ($parent !== null) {
            $buffer['parent'] = $parent;
        }

        // Out of order means a chunk was lost or duplicated. Reordering would
        // guess at content; recording what arrived and SAYING it is partial
        // does not.
        if ($index !== $buffer['next']) {
            $buffer['incomplete'] = true;
        } else {
            $length = strlen($piece);
            $overOne = $buffer['bytes'] + $length > self::MAX_TOTAL_RESULT_BYTES;
            $overAll = $this->toolResultBytes + $length > self::MAX_OPEN_RESULT_BYTES;
            if ($overOne || $overAll) {
                // `refused`, not `incomplete`. Every chunk arrived; THIS side
                // declined to hold them. Marking it the same way as a result
                // the bridge never finished sending sends whoever debugs it to
                // the wrong machine, and the two are not remotely the same
                // problem.
                $buffer['refused'] = $overOne ? 'result' : 'turn';
                Log::warning('AI Bridge: dropping tool result content over a memory ceiling', [
                    'request_id' => $this->requestId,
                    'tool_call_id' => $toolCallId,
                    'over_result_ceiling' => $overOne,
                    'over_turn_ceiling' => $overAll,
                ]);
            } else {
                $buffer['parts'][] = $piece;
                $buffer['bytes'] += $length;
                $this->toolResultBytes += $length;
            }
            $buffer['next'] = $index + 1;
        }

        // Carried into the text rather than a new field, so nothing downstream
        // has to change in order to stop discarding it. Held until the flush
        // rather than pushed in as it arrives: on a non-final chunk it would
        // otherwise land in the MIDDLE of the content.
        if ($droppedNote !== '') {
            $buffer['dropped_note'] = $droppedNote;
        }

        $this->toolResultChunks[$toolCallId] = $buffer;

        if ($final) {
            $this->flushToolResult($toolCallId);
        }
    }

    /**
     * Take what has been assembled for one call, forgetting the buffer.
     *
     * @return array{result: string, is_error: bool|null, parent: string|null}|null
     */
    private function takeToolResult(string $toolCallId): ?array
    {
        $buffer = $this->toolResultChunks[$toolCallId] ?? null;
        if ($buffer === null) {
            return null;
        }

        unset($this->toolResultChunks[$toolCallId]);
        $this->toolResultBytes -= $buffer['bytes'];

        $result = implode('', $buffer['parts']);

        // BOTH, not one or the other. A buffer can hit a ceiling AND never
        // receive its final chunk, and reporting only the refusal drops the
        // second fault silently — they are different problems with different
        // fixes.
        if (($buffer['refused'] ?? false) === 'result') {
            $result .= "\n…[truncated by the server: this result alone exceeded the memory one result may use]";
        } elseif (($buffer['refused'] ?? false) === 'turn') {
            $result .= "\n…[truncated by the server: this turn's tool results together exceeded the memory one turn may use]";
        }

        if ($buffer['incomplete']) {
            $result .= "\n…[incomplete: the stream ended before this result finished]";
        }

        $result .= $buffer['dropped_note'] ?? '';

        return ['result' => $result, 'is_error' => $buffer['is_error'], 'parent' => $buffer['parent'] ?? null];
    }

    /**
     * Dispatch a completed result mid-stream, through the ordinary guard.
     */
    private function flushToolResult(string $toolCallId): void
    {
        $assembled = $this->takeToolResult($toolCallId);
        if ($assembled === null) {
            return;
        }

        $this->dispatchToolResult($toolCallId, $assembled['result'], $assembled['is_error'], $assembled['parent']);
    }

    /**
     * Dispatch every partially assembled result, in arrival order.
     *
     * Called at each of the three terminals. A result whose final chunk never
     * arrived is worth more as a marked partial than as nothing at all, and the
     * recorder already keeps partial TEXT on the same terminals — a tool result
     * disappearing where the prose survives would be the odd one out.
     *
     * Dispatched to the callbacks directly rather than through
     * `dispatchToolResult`, which refuses to run once the stream is cancelled
     * or terminated. That guard is right for a result still arriving and wrong
     * here: these are precisely the moments it would drop the results this
     * exists to save.
     */
    private function flushPendingToolResults(): void
    {
        foreach (array_keys($this->toolResultChunks) as $toolCallId) {
            $this->toolResultChunks[$toolCallId]['incomplete'] = true;
            $assembled = $this->takeToolResult($toolCallId);
            if ($assembled === null) {
                continue;
            }

            $this->dispatchCallbacks(
                $this->toolResultCallbacks,
                [$toolCallId, $assembled['result'], $assembled['is_error'], $assembled['parent']],
                'toolResult',
            );
        }
    }

    /**
     * Dispatch a tool_result event to all registered callbacks.
     *
     * @internal Called when the bridge acknowledges receipt of a tool result.
     */
    public function dispatchToolResult(string $toolCallId, mixed $result, ?bool $isError = null, ?string $parentToolUseId = null): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        Log::debug('AI Bridge: tool_result received', [
            'request_id' => $this->requestId,
            'tool_call_id' => $toolCallId,
        ]);

        $this->dispatchCallbacks($this->toolResultCallbacks, [$toolCallId, $result, $isError, $parentToolUseId], 'toolResult');
    }

    /**
     * Dispatch an attachment event to all registered callbacks.
     *
     * Not terminal: a `done` still follows, and this must not end the turn.
     *
     * It IS dropped after a cancel or a terminal event, like every other
     * non-terminal event — and that is a known rough edge rather than a
     * decision. The file is already stored server-side by the time this
     * arrives, so a turn cancelled in the window between the upload and the
     * event leaves an attachment in the app's store that nothing links to. In
     * practice the envelope rarely gets this far: an aborted turn has already
     * had its pending request removed, so the event is dropped upstream in
     * MessageHandler as belonging to an unknown request. Worth cleaning up
     * with an orphan sweep on the app side if it ever matters.
     *
     * @param  array<string, mixed>  $attachment
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchAttachment(array $attachment): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        Log::debug('AI Bridge: attachment received', [
            'request_id' => $this->requestId,
            'attachment_id' => $attachment['id'] ?? null,
        ]);

        $this->dispatchCallbacks($this->attachmentCallbacks, [$attachment], 'attachment');
    }

    /**
     * Dispatch a cancelled event to all registered callbacks.
     *
     * Unlike error, this emits event type 'cancelled' so consumers can
     * differentiate between errors and intentional cancellation.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchCancelled(string $reason = 'Request was cancelled.'): void
    {
        if ($this->terminated) {
            return;
        }

        // Before the cancelled callbacks, not after: the recorder persists what
        // it has from inside one of them, so a result flushed afterwards would
        // be assembled correctly and then never written down.
        $this->flushPendingToolResults();

        $this->terminated = true;

        $this->dispatchCallbacks($this->cancelledCallbacks, [$reason], 'cancelled');

        $this->dispatchStreamCompleted(false, null, "cancelled: {$reason}", TerminatedBy::Cancelled);

        $this->provider->markCompleted();
    }

    /**
     * Dispatch a raw StreamEvent. Routes to the appropriate typed dispatch method.
     *
     * @internal Useful for providers that produce StreamEvent objects directly.
     */
    public function dispatchEvent(StreamEvent $event): void
    {
        match ($event->event) {
            MessageTypes::BLOCK_START => $this->dispatchBlockStartFromEvent($event),
            MessageTypes::BLOCK_DELTA => $this->dispatchBlockDeltaFromEvent($event),
            MessageTypes::BLOCK_STOP => $this->dispatchBlockStopFromEvent($event),
            MessageTypes::TOOL_CALL => $this->dispatchToolCall(
                $event->data['tool_name'],
                $event->data['parameters'] ?? [],
                $event->data['tool_call_id'] ?? $event->data['call_id'] ?? '',
            ),
            MessageTypes::TOOL_RESULT => $this->receiveToolResult($event->data),
            MessageTypes::RATE_LIMIT => $this->dispatchRateLimit(
                is_string($event->data['provider'] ?? null) ? $event->data['provider'] : 'unknown',
                is_array($event->data['info'] ?? null) ? $event->data['info'] : [],
            ),
            MessageTypes::TASK => $this->dispatchTask($event->data),
            MessageTypes::ATTACHMENT => $this->dispatchAttachment($event->data),
            MessageTypes::DONE => $this->dispatchDone(
                $event->data['usage'] ?? null,
                array_diff_key($event->data, ['usage' => true]),
            ),
            MessageTypes::ERROR => $this->dispatchError(
                $event->data['code'] ?? 'unknown',
                $event->data['message'] ?? 'Unknown error',
            ),
            // Ignored, not an error: PROTOCOL.md has a consumer skip an event
            // it does not know. Logged at debug so a new event from a newer
            // bridge is visible to whoever looks, instead of vanishing.
            default => Log::debug('AI Bridge: ignoring unknown stream event', [
                'request_id' => $this->requestId,
                'event' => $event->event,
            ]),
        };
    }

    /**
     * The helper a frame belongs to, or null for the main assistant.
     *
     * Absent, null and an empty string all mean the main assistant; the bridge
     * never sends the last two, but a stray one must not turn a main-assistant
     * call into a helper's with an id that matches nothing.
     *
     * @param  array<string, mixed>  $data
     */
    private static function parentOf(array $data): ?string
    {
        $parent = $data['parent_tool_use_id'] ?? null;

        return is_string($parent) && $parent !== '' ? $parent : null;
    }

    /**
     * Validate block_type and dispatch block_start from a raw StreamEvent.
     */
    private function dispatchBlockStartFromEvent(StreamEvent $event): void
    {
        $blockType = BlockType::tryFrom($event->data['block_type'] ?? '');
        if ($blockType === null) {
            Log::warning('AI Bridge: unknown block_type in block_start, skipping', [
                'request_id' => $this->requestId,
                'block_type' => $event->data['block_type'] ?? '',
            ]);

            return;
        }
        $blockIndex = (int) ($event->data['block_index'] ?? 0);
        $this->blockTypes[$blockIndex] = $blockType;

        // The bridge sends these for every provider; rebuilding the event from
        // only type and index is what threw them away, and is why a chat could
        // say "4 tool calls" and never what any of them were.
        $toolName = $event->data['tool_name'] ?? null;
        $toolCallId = $event->data['tool_call_id'] ?? null;

        $this->dispatchBlockStart(
            $blockType,
            $blockIndex,
            is_string($toolName) && $toolName !== '' ? $toolName : null,
            is_string($toolCallId) && $toolCallId !== '' ? $toolCallId : null,
            self::parentOf($event->data),
        );
    }

    /**
     * Dispatch block_delta from a raw StreamEvent.
     *
     * block_delta events carry only `block_index` + `content` (PROTOCOL.md),
     * so the type is resolved from the matching block_start via resolveBlockType().
     */
    private function dispatchBlockDeltaFromEvent(StreamEvent $event): void
    {
        $blockIndex = (int) ($event->data['block_index'] ?? 0);
        $this->dispatchBlockDelta(
            $this->resolveBlockType($event, $blockIndex),
            $blockIndex,
            $event->data['content'] ?? '',
        );
    }

    /**
     * Dispatch block_stop from a raw StreamEvent.
     *
     * Like block_delta, block_stop carries only `block_index` — the type is
     * resolved from the matching block_start.
     */
    private function dispatchBlockStopFromEvent(StreamEvent $event): void
    {
        $blockIndex = (int) ($event->data['block_index'] ?? 0);
        $this->dispatchBlockStop($this->resolveBlockType($event, $blockIndex), $blockIndex);
    }

    /**
     * Resolve the block type for a block_delta / block_stop event.
     *
     * An explicit `block_type` on the event wins (legacy / BYOK providers that
     * include it); otherwise fall back to the type recorded from this block's
     * block_start, then to Text as a last resort.
     */
    private function resolveBlockType(StreamEvent $event, int $blockIndex): BlockType
    {
        return BlockType::tryFrom($event->data['block_type'] ?? '')
            ?? $this->blockTypes[$blockIndex]
            ?? BlockType::Text;
    }

    /**
     * Dispatch a set of callbacks with the given arguments.
     *
     * Centralizes the try/catch/Log::error pattern across all dispatch methods.
     * Each callback is invoked with the given $args; any Throwable is caught and
     * logged with the callback type name.
     *
     * @param  Closure[]  $callbacks  The array of callbacks to invoke.
     * @param  array<mixed>  $args    Arguments to pass to each callback.
     * @param  string  $callbackType  Human-readable type name for log messages.
     */
    private function dispatchCallbacks(array $callbacks, array $args, string $callbackType): void
    {
        foreach ($callbacks as $callback) {
            try {
                $callback(...$args);
            } catch (\Throwable $e) {
                Log::error("AI Bridge: exception in {$callbackType} callback", [
                    'request_id' => $this->requestId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Dispatch the StreamCompleted Laravel event for logging/analytics.
     *
     * The $terminatedBy parameter lets event listeners distinguish cancellations
     * from errors without fragile string parsing.
     */
    private function dispatchStreamCompleted(bool $success, ?array $usage = null, ?string $error = null, TerminatedBy $terminatedBy = TerminatedBy::Success): void
    {
        $durationMs = $this->startedAt > 0
            ? (int) ((microtime(true) - $this->startedAt) * 1000)
            : null;

        if ($this->mode === null) {
            Log::warning('AI Bridge: StreamHandler mode not set, defaulting to Byok. Call setMode() before start().');
        }
        $mode = $this->mode ?? ProviderMode::Byok;

        try {
            Event::dispatch(new StreamCompleted(
                conversationId: $this->conversationId,
                requestId: $this->requestId,
                mode: $mode,
                success: $success,
                usage: $usage,
                error: $error,
                durationMs: $durationMs,
                terminatedBy: $terminatedBy,
            ));
        } catch (\Throwable $e) {
            Log::error('AI Bridge: failed to dispatch StreamCompleted event', [
                'request_id' => $this->requestId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
