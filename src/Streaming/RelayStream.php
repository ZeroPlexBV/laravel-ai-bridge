<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Conversation;

/**
 * Serve-side counterpart of BridgeStream for relayed (PHP-FPM) requests.
 *
 * When a request originates under PHP-FPM, BridgeStream relays it to the
 * bridge server's internal HTTP API. The actual AI response events then
 * arrive asynchronously at the separate `ai-bridge:serve` process — never
 * at the PHP-FPM worker that issued the request. The web worker's
 * BufferingSink and ConversationRecorder live in the dead PHP-FPM worker
 * and therefore never see those events.
 *
 * RelayStream bridges that gap. The serve process registers a RelayStream as
 * the pending request for each relayed request_id and attaches a parallel
 * {@see BufferingSink} (so events land in the per-turn event buffer for the
 * browser's SSE tail) and {@see ConversationRecorder} (so the assistant row
 * is persisted at terminal).
 *
 * Unlike BridgeStream, RelayStream does not drive the stream — the remote
 * bridge already received the ai_request. start() and cancel() are no-ops.
 */
final class RelayStream implements StreamableProvider
{
    private readonly StreamHandler $streamHandler;

    /**
     * @param  string  $requestId       The relayed request's ID (preserved for event routing).
     * @param  string  $conversationId  The conversation ID, for persistence + StreamCompleted reporting.
     * @param  string|null  $model  The model the turn was sent with, when it named one; the
     *                              assistant row records it rather than the conversation's.
     */
    public function __construct(
        string $requestId,
        string $conversationId = '',
        ?string $model = null,
    ) {
        $this->streamHandler = new StreamHandler($this, $requestId);
        $this->streamHandler->setMode(ProviderMode::Bridge);
        $this->streamHandler->setConversationId($conversationId);

        // Attach the stream-store buffering sink in this (serve) process so
        // every event the bridge delivers lands in the per-turn buffer the
        // browser's SSE tail reads. The web worker also attaches a sink to
        // its (separate) StreamHandler instance, but that one never sees
        // events under PHP-FPM — its writes happen here.
        try {
            BufferingSink::attach($this->streamHandler, app(StreamStoreContract::class));
        } catch (\Throwable $e) {
            Log::error('AI Bridge: failed to attach buffering sink to relayed stream', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }

        // Bridge-mode stream events arrive in THIS (serve) process, so this is
        // where bridge-conversation persistence must happen. When the relayed
        // request belongs to a persisted conversation (numeric DB id), attach a
        // recorder that writes the assistant turn on completion. The user turn
        // was already persisted by the web process before the request relayed.
        if ($conversationId !== '' && is_numeric($conversationId)) {
            try {
                $conversation = Conversation::find($conversationId);
                if ($conversation !== null) {
                    ConversationRecorder::attach($this->streamHandler, $conversation, $model);
                }
            } catch (\Throwable $e) {
                Log::warning('AI Bridge: could not attach conversation recorder to relayed stream', [
                    'conversation_id' => $conversationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function setConversationId(string $conversationId): static
    {
        $this->streamHandler->setConversationId($conversationId);

        return $this;
    }

    public function setMessage(string $message): static
    {
        return $this;
    }

    public function setOptions(array $options): static
    {
        return $this;
    }

    /**
     * No-op — the remote bridge already received the ai_request and drives the stream.
     */
    public function start(): void
    {
        // Intentionally empty: RelayStream does not originate the request.
    }

    /**
     * No-op — cancellation of relayed requests is handled by the relay path, not here.
     */
    public function cancel(): void
    {
        // Intentionally empty: RelayStream does not drive the stream.
    }

    /**
     * No-op — completion tracking is not needed for relayed requests.
     */
    public function markCompleted(): void
    {
        // Intentionally empty.
    }

    public function getStreamHandler(): StreamHandler
    {
        return $this->streamHandler;
    }
}
