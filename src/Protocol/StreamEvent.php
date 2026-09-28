<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Protocol;

use Tetrix\AiBridge\Enums\BlockType;

/**
 * Immutable value object representing a single event in an AI response stream.
 *
 * StreamEvents are the unified currency of the streaming interface — regardless
 * of whether the response comes from a CLI bridge, BYOK, or managed provider,
 * consuming code always receives StreamEvent instances.
 */
final class StreamEvent
{
    public function __construct(
        /** Unique identifier for the AI request that produced this event. */
        public readonly string $requestId,
        /** Event type: block_start, block_delta, block_stop, tool_call, tool_result, task, done, error, … */
        public readonly string $event,
        /** Event-specific payload data. */
        public readonly array $data,
    ) {}

    /**
     * Create a block_start event.
     *
     * $toolName and $toolCallId apply to `tool_call` blocks. They are optional
     * because a provider may not report them, but when the bridge sends them —
     * and it does, for every provider — dropping them here left a chat able to
     * say only "4 tool calls", never "3 commands, 1 file read".
     *
     * $parentToolUseId marks a block a helper (sub-agent) produced: it is the
     * `tool_call_id` of the call that spawned the helper. Null means the main
     * assistant and is left out of `data` entirely — absent is what every
     * block meant before the field existed, so a consumer that ignores it sees
     * exactly the stream it always did.
     */
    public static function blockStart(
        string $requestId,
        BlockType $blockType,
        int $blockIndex,
        ?string $toolName = null,
        ?string $toolCallId = null,
        ?string $parentToolUseId = null,
    ): self {
        return new self($requestId, MessageTypes::BLOCK_START, array_filter([
            'block_type' => $blockType->value,
            'block_index' => $blockIndex,
            'tool_name' => $toolName,
            'tool_call_id' => $toolCallId,
            'parent_tool_use_id' => $parentToolUseId,
        ], static fn ($value) => $value !== null));
    }

    /**
     * Create a block_delta event.
     */
    public static function blockDelta(string $requestId, BlockType $blockType, int $blockIndex, string $content): self
    {
        return new self($requestId, MessageTypes::BLOCK_DELTA, [
            'block_type' => $blockType->value,
            'block_index' => $blockIndex,
            'content' => $content,
        ]);
    }

    /**
     * Create a block_stop event.
     */
    public static function blockStop(string $requestId, BlockType $blockType, int $blockIndex): self
    {
        return new self($requestId, MessageTypes::BLOCK_STOP, [
            'block_type' => $blockType->value,
            'block_index' => $blockIndex,
        ]);
    }

    /**
     * Create a tool_call event.
     *
     * The canonical key for the tool call ID is 'tool_call_id'. The legacy 'call_id'
     * key is retained by dispatchEvent() as a read fallback for backward compatibility
     * with externally-sourced events, but new events created by this factory always
     * use 'tool_call_id'.
     */
    public static function toolCall(string $requestId, string $toolName, array $params, string $callId): self
    {
        return new self($requestId, MessageTypes::TOOL_CALL, [
            'tool_name' => $toolName,
            'parameters' => $params,
            'tool_call_id' => $callId,
        ]);
    }

    /**
     * Create a tool_result event.
     *
     * $isError is the authoritative failure signal. It cannot be read back out
     * of $result: a tool that legitimately prints "Error: no matches" looks
     * exactly like one that failed.
     *
     * $parentToolUseId is set when the call was a helper's, exactly as on the
     * call's block_start, and left out of `data` when null.
     */
    public static function toolResult(
        string $requestId,
        string $toolCallId,
        mixed $result,
        ?bool $isError = null,
        ?string $parentToolUseId = null,
    ): self {
        // Only is_error is conditional. Filtering `result` too would erase a
        // null result — which a failing tool commonly has — and a consumer
        // waiting for `result` to appear would show the call as still running
        // for ever.
        $data = ['tool_call_id' => $toolCallId, 'result' => $result];
        if ($isError !== null) {
            $data['is_error'] = $isError;
        }
        if ($parentToolUseId !== null) {
            $data['parent_tool_use_id'] = $parentToolUseId;
        }

        return new self($requestId, MessageTypes::TOOL_RESULT, $data);
    }

    /**
     * Create a task event — the life of a helper (sub-agent, or background
     * command) the CLI runs for the main assistant. Informational, non-terminal.
     *
     * $data is the bridge's payload as it stands: `phase`, `task_id`,
     * `tool_use_id` and whatever else that phase carries. Passed through whole
     * rather than rebuilt from known keys, so a field a newer bridge adds
     * reaches the consumer instead of dying here.
     *
     * @param  array<string, mixed>  $data
     */
    public static function task(string $requestId, array $data): self
    {
        return new self($requestId, MessageTypes::TASK, $data);
    }

    /**
     * Create a rate_limit event — informational, non-terminal.
     */
    public static function rateLimit(string $requestId, string $provider, array $info): self
    {
        return new self($requestId, MessageTypes::RATE_LIMIT, [
            'provider' => $provider,
            'info' => $info,
        ]);
    }

    /**
     * Create a done event.
     *
     * $meta carries everything the provider reported about the turn besides the
     * token counts — the model that actually ran, cost, duration, stop reason,
     * permission denials. Kept separate from $usage so that existing callbacks
     * taking only usage keep working unchanged.
     */
    public static function done(string $requestId, ?array $usage = null, array $meta = []): self
    {
        return new self($requestId, MessageTypes::DONE, [
            'usage' => $usage,
        ] + $meta);
    }

    /**
     * Create an error event.
     */
    public static function error(string $requestId, string $code, string $message): self
    {
        return new self($requestId, MessageTypes::ERROR, [
            'code' => $code,
            'message' => $message,
        ]);
    }

    /**
     * Get the block type from the event data, if applicable.
     */
    public function blockType(): ?BlockType
    {
        if (! isset($this->data['block_type'])) {
            return null;
        }

        return BlockType::tryFrom($this->data['block_type']);
    }

    /**
     * Serialize the event to the protocol envelope format for WebSocket transmission.
     *
     * Per PROTOCOL.md, streaming events use an envelope:
     *   { "type": "stream", "request_id": "...", "event": "<event_type>", "data": {...} }
     */
    public function toArray(): array
    {
        return [
            'type' => MessageTypes::STREAM,
            'request_id' => $this->requestId,
            'event' => $this->event,
            'data' => $this->data,
        ];
    }

    /**
     * Create a StreamEvent from an incoming array (e.g. decoded WebSocket message).
     *
     * Supports both the envelope format (type: "stream" with event field)
     * and legacy flat format (type is the event type directly).
     */
    public static function fromArray(array $payload): self
    {
        // Envelope format: { "type": "stream", "event": "block_start", ... }
        if (($payload['type'] ?? '') === MessageTypes::STREAM && isset($payload['event'])) {
            return new self(
                requestId: $payload['request_id'] ?? '',
                event: $payload['event'],
                data: $payload['data'] ?? [],
            );
        }

        // Legacy flat format: { "type": "block_start", ... }
        return new self(
            requestId: $payload['request_id'] ?? '',
            event: $payload['type'] ?? '',
            data: $payload['data'] ?? [],
        );
    }
}
