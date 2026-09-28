<?php

declare(strict_types=1);

namespace Tetrix\AiBridge;

use Closure;
use InvalidArgumentException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Connections\ConnectionStatus;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Contracts\ToolHandler;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Models\Message;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\BridgeStream;
use Tetrix\AiBridge\Streaming\BufferingSink;
use Tetrix\AiBridge\Streaming\ChatCompletionsStream;
use Tetrix\AiBridge\Streaming\ConversationRecorder;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Support\BridgeLog;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/**
 * Main manager class — the unified interface for the AI Bridge package.
 *
 * This is the class behind the AiBridge facade. It provides the primary
 * API for consuming applications:
 *
 *   $stream = AiBridge::stream($conversationId, $message, ['system_prompt' => '...']);
 *   $stream->onBlockDelta(fn (StreamEvent $e) => echo $e->data['content']);
 *   $stream->onDone(fn (?array $usage) => logger('Done!'));
 *   $stream->start();
 *
 * The manager determines which provider to use based on configuration and
 * creates the appropriate StreamableProvider implementation.
 */
class AiBridgeManager
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly BridgeConnectionManager $connectionManager,
        private readonly TokenManager $tokenManager,
    ) {}

    /**
     * Create a new streaming session for an AI request.
     *
     * Returns a StreamHandler with the appropriate provider configured.
     * The consuming app registers callbacks on the StreamHandler, then calls start().
     *
     * @param  string  $conversationId  Unique conversation identifier.
     * @param  string  $message  The user's message to send to the AI.
     * @param  array<string, mixed>  $options  Additional options:
     *   - 'system_prompt': System prompt for the AI.
     *   - 'messages': Conversation history (array of role/content pairs).
     *   - 'temperature': Sampling temperature.
     *   - 'max_tokens': Maximum tokens in response.
     *   - 'model': Override the configured model.
     *   - 'api_key': Override API key for BYOK (server-side only, stripped from HTTP input by StreamController).
     *   - 'mode': ProviderMode enum instance (server-side only, stripped from HTTP input by StreamController).
     *   - 'user_id': User ID for bridge mode (server-side only, stripped from HTTP input by StreamController).
     * @return StreamHandler
     */
    public function stream(string $conversationId, string $message, array $options = []): StreamHandler
    {
        return $this->buildStream($conversationId, $message, $options);
    }

    /**
     * Build a configured StreamHandler from the given options.
     *
     * @param  array<string, mixed>  $options
     */
    private function buildStream(string $conversationId, string $message, array $options): StreamHandler
    {
        $mode = $this->resolveMode($options);
        $provider = $this->createProvider($mode, $options);

        $provider->setConversationId($conversationId);
        $provider->setMessage($message);
        $provider->setOptions($options);

        $handler = $provider->getStreamHandler();
        $handler->setConversationId($conversationId);
        $handler->setMode($mode);

        return $handler;
    }

    /**
     * Register a tool the AI can call.
     *
     * @param  string  $name  Unique tool name.
     * @param  string  $description  Human-readable description.
     * @param  array<string, mixed>  $parameters  JSON Schema for parameters.
     * @param  Closure  $handler  The function that executes the tool.
     * @return $this
     */
    public function registerTool(string $name, string $description, array $parameters, Closure $handler): static
    {
        $this->toolRegistry->register($name, $description, $parameters, $handler);

        return $this;
    }

    /**
     * Register a tool from a ToolHandler class.
     *
     * @param  ToolHandler  $handler
     * @return $this
     */
    public function registerToolHandler(ToolHandler $handler): static
    {
        $this->toolRegistry->registerHandler($handler);

        return $this;
    }

    /**
     * Get the tool registry.
     */
    public function tools(): ToolRegistry
    {
        return $this->toolRegistry;
    }

    /**
     * Get the bridge connection manager.
     */
    public function connections(): BridgeConnectionManager
    {
        return $this->connectionManager;
    }

    /**
     * What is left of the subscription the bridge for this connection is signed in as.
     *
     * Asks the machine and returns figures only: the credential that answers this lives on
     * that machine and is never sent here, which is why the bridge does the asking.
     *
     * Takes a Connection the caller has already fetched, NOT a key to look up. An earlier
     * shape took `connection_key` and resolved it with an unscoped query, which made the
     * obvious consuming route — `Route::get('/usage/{key}', fn ($key) => AiBridge::usage($key))`
     * — read any user's figures. Every other path into ConnectionStatus goes through the
     * app's own `connectionsQuery()` scope; this now cannot skip it, because authorising the
     * lookup is the caller's job and a signature that accepts a bare key invites forgetting.
     *
     * @return array{ok: bool, limits?: array<int, array<string, mixed>>, reason?: string}
     *                                 Each limit carries `label` and `percent`, plus
     *                                 `resets_at`, `kind` and `group` when the CLI reports
     *                                 them. On failure, `reason` is `not_connected`,
     *                                 `unsupported`, `no_credential` or `failed`.
     */
    public function usage(Connection $connection, ?string $provider = null): array
    {
        return app(ConnectionStatus::class)->usage($connection, $provider);
    }

    /**
     * Check if a user has an active bridge connection.
     */
    public function hasBridge(int|string $userId): bool
    {
        return $this->connectionManager->hasConnection($userId);
    }

    /**
     * SSE streaming — returns a StreamedResponse that delivers normalized
     * AI events as Server-Sent Events.
     *
     * Each event is sent as: data: {"event": "...", "data": {...}}
     * The stream ends with: data: [DONE]
     *
     * @param  string  $conversationId  Unique conversation identifier.
     * @param  string  $message  The user's message to send to the AI.
     * @param  array<string, mixed>  $options  Additional options (system_prompt, temperature, etc.).
     * @return StreamedResponse
     */
    public function streamToResponse(string $conversationId, string $message, array $options = []): StreamedResponse
    {
        return new StreamedResponse(function () use ($conversationId, $message, $options) {
            $stream = $this->stream($conversationId, $message, $options);

            $send = function (array $payload): void {
                echo 'data: ' . json_encode($payload) . "\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $sendTerminal = function () {
                echo "data: [DONE]\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $this->wireCallbacks($stream, $send, $sendTerminal);

            // Emit the conversation_id as the first SSE event so the browser can
            // use it in subsequent multi-turn messages — an auto-generated id
            // would otherwise be lost.
            $send(['event' => 'conversation_id', 'data' => ['conversation_id' => $conversationId]]);

            $stream->start();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // ── Conversation streaming ────────────────────────────────────────

    /**
     * Build a configured StreamHandler for a persisted conversation.
     *
     * Persists the user turn, injects prior history + the conversation's
     * provider/model/mode/system_prompt, and attaches persistence so the
     * assistant turn is written when the stream completes.
     *
     * @param  array<string, mixed>  $options  Extra options (rarely needed —
     *   provider/model/mode/system_prompt come from the conversation).
     */
    public function streamConversation(Conversation $conversation, string $message, array $options = []): StreamHandler
    {
        $conversation->loadMissing('messages', 'connection');

        // Capture prior history BEFORE persisting the new user turn.
        $history = $conversation->historyFor();

        // Persist the user turn immediately.
        $conversation->appendMessage(Message::ROLE_USER, $message);

        $mode = ProviderMode::from($conversation->mode);
        $options['mode'] = $mode;
        $options['messages'] = $history;
        // Which registered tools this conversation exposes (null = all).
        $options['allowed_tools'] = $conversation->allowed_tools;

        if (! empty($conversation->system_prompt)) {
            $options['system_prompt'] = $conversation->system_prompt;
        }
        if (! empty($conversation->model)) {
            $options['model'] = $conversation->model;
        }

        $connection = $conversation->connection;

        if ($mode === ProviderMode::Bridge) {
            $options['provider'] = $conversation->provider ?? '';
            // The CLI session the bridge should resume for this conversation.
            // Null on the first turn (or after a lost session was wiped) — the
            // bridge then starts fresh and reports the new id back on `done`,
            // which the serve process persists. The server owns this mapping;
            // see BridgeStream::buildRequestBody() for how it shapes the
            // request (history is sent only when this is null).
            $options['cli_session_id'] = $conversation->cli_session_id;
            // Where the assistant works, for the life of this conversation.
            // Sent on every turn from the conversation rather than from the
            // caller, because the bridge fixes the directory for a CLI
            // session's life: a resume naming a different one is refused with
            // `working_dir_changed`. Taking it from one place removes the
            // opportunity for the second turn to disagree with the first.
            // A caller may still set it explicitly for the FIRST turn (see
            // ConversationController::stream), which is what persists it.
            if (! empty($conversation->working_dir)) {
                $options['working_dir'] = $conversation->working_dir;
            }
            if ($connection !== null && ! empty($connection->connection_key)) {
                $options['user_id'] = $connection->connection_key;
            }
        } elseif ($mode === ProviderMode::Byok && $connection !== null) {
            if (! empty($connection->endpoint)) {
                $options['endpoint'] = $connection->endpoint;
            }
            if (! empty($connection->api_key)) {
                $options['api_key'] = $connection->api_key;
            }
        }

        $handler = $this->buildStream((string) $conversation->id, $message, $options);

        ConversationRecorder::attach($handler, $conversation);

        return $handler;
    }

    /**
     * SSE streaming for a persisted conversation (BYOK / Managed modes).
     */
    public function streamConversationToResponse(Conversation $conversation, string $message, array $options = []): StreamedResponse
    {
        return new StreamedResponse(function () use ($conversation, $message, $options) {
            $stream = $this->streamConversation($conversation, $message, $options);

            $send = function (array $payload): void {
                echo 'data: ' . json_encode($payload) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };
            $sendTerminal = function (): void {
                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $this->wireCallbacks($stream, $send, $sendTerminal);

            $send(['event' => 'conversation_id', 'data' => ['conversation_id' => (string) $conversation->id]]);

            $stream->start();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Start a buffered conversation stream and return its request_id.
     *
     * Initialises a per-turn entry in the {@see StreamStoreContract} buffer,
     * marks the conversation as actively streaming, and kicks off the stream
     * in a `terminating()` callback so the HTTP response carrying the
     * request_id reaches the browser before the bridge round-trip starts.
     * The browser then opens an SSE tail at `/ai-bridge/streams/{rid}/events`.
     *
     * Bridge mode is the primary caller (events arrive in the serve process,
     * where {@see RelayStream} attaches a parallel {@see BufferingSink} to
     * the relayed StreamHandler). BYOK/Managed callers can also use this —
     * the stream runs synchronously in the web worker's terminating phase
     * and writes events to the buffer there.
     */
    public function startConversationStream(Conversation $conversation, string $message, array $options = []): string
    {
        $stream = $this->streamConversation($conversation, $message, $options);
        $requestId = $stream->requestId;

        $store = app(StreamStoreContract::class);
        $store->start($requestId, [
            'conversation_id' => (string) $conversation->id,
            'started_at' => now()->toIso8601String(),
            'provider' => $conversation->provider,
            'model' => $conversation->model,
        ]);

        // Mark the conversation as actively streaming so a fresh page-load can
        // see "is something in flight here?" without scanning the buffer.
        // Cleared by ConversationRecorder when the turn terminates.
        try {
            $conversation->forceFill(['streaming_request_id' => $requestId])->save();
        } catch (\Throwable $e) {
            Log::warning('AI Bridge: could not set streaming_request_id', [
                'conversation_id' => $conversation->id,
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }

        // Attach the buffering sink to the StreamHandler in THIS process.
        // Bridge mode under PHP-FPM never sees events here (they arrive in the
        // serve process and are buffered by the parallel sink there); this
        // attach is harmless then. BYOK/Managed modes run the stream inline
        // and the buffer is populated by this sink directly.
        BufferingSink::attach($stream, $store);

        BridgeLog::info('starting buffered conversation stream', [
            'conversation_id' => $conversation->id,
            'request_id' => $requestId,
        ]);

        $this->startAfterResponse($stream, $requestId, $store);

        return $requestId;
    }

    /**
     * Run a wired stream's start() after the HTTP response has been sent.
     *
     * Registered as a terminating callback — NOT dispatched via
     * dispatch()->afterResponse(). The stream callbacks hold Closures that
     * afterResponse() would try to serialize, which fails silently and leaves
     * the chat UI hanging. Terminating callbacks invoke directly without
     * serialization.
     *
     * Failures are logged and surfaced as a buffer `error` event so the UI
     * leaves its "Thinking" state instead of hanging on a dead stream.
     */
    private function startAfterResponse(StreamHandler $stream, string $requestId, StreamStoreContract $store): void
    {
        app()->terminating(function () use ($stream, $requestId, $store): void {
            try {
                BridgeLog::verbose('starting deferred stream', [
                    'request_id' => $requestId,
                ]);

                $stream->start();
            } catch (\Throwable $e) {
                BridgeLog::error('deferred stream start failed', [
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                ]);

                // Route the failure through dispatchError so every attached
                // sink runs: BufferingSink writes the error event and flips
                // status=failed, ConversationRecorder clears the conversation's
                // streaming_request_id. Writing directly to the store would
                // skip the recorder and leave streaming_request_id pointing
                // at a now-dead turn.
                try {
                    $stream->dispatchError(
                        'stream_start_failed',
                        'The request could not be started.',
                    );
                } catch (\Throwable) {
                    // Sinks unavailable — fall back to writing the error
                    // directly so the SSE tail still sees a terminal.
                    try {
                        $store->appendEvent($requestId, MessageTypes::ERROR, [
                            'code' => 'stream_start_failed',
                            'message' => 'The request could not be started.',
                        ]);
                        $store->complete($requestId, 'failed');
                    } catch (\Throwable) {
                        // Buffer unavailable too — the logged error is the record.
                    }
                }
            }
        });
    }

    /**
     * Wire the stream callbacks to a sink callable.
     *
     * Callbacks: onBlockStart, onBlockDelta, onBlockStop, onToolCall,
     * onToolResult, onRateLimit, onAttachment, onTask, onDone, onError,
     * onCancelled.
     * The $sink receives a normalized payload array with 'event' and 'data' keys.
     * The optional $onTerminal callback is called after done/error/cancelled events (e.g. for SSE [DONE] flush).
     */
    private function wireCallbacks(StreamHandler $stream, callable $sink, ?Closure $onTerminal = null): void
    {
        $suppressThinking = (bool) config('ai-bridge.streaming.suppress_thinking_blocks', true);

        // Thinking suppression check shared by all three block callbacks.
        $shouldSuppressEvent = static function (StreamEvent $event) use ($suppressThinking): bool {
            return $suppressThinking && ($event->data['block_type'] ?? '') === 'thinking';
        };

        // When thinking blocks are suppressed, re-index visible blocks from 0 so
        // consumers always receive a contiguous zero-based block_index sequence.
        $visibleBlockCounter = 0;
        // Track whether a block is currently suppressed so delta/stop share the same decision.
        $currentBlockSuppressed = false;

        $stream->onBlockStart(function (StreamEvent $event) use ($sink, $shouldSuppressEvent, &$visibleBlockCounter, &$currentBlockSuppressed) {
            $currentBlockSuppressed = $shouldSuppressEvent($event);
            if ($currentBlockSuppressed) {
                return;
            }
            $data = $event->data;
            $data['block_index'] = $visibleBlockCounter;
            $sink(['event' => $event->event, 'data' => $data]);
        });

        $stream->onBlockDelta(function (StreamEvent $event) use ($sink, &$currentBlockSuppressed, &$visibleBlockCounter) {
            if ($currentBlockSuppressed) {
                return;
            }
            $data = $event->data;
            $data['block_index'] = $visibleBlockCounter;
            $sink(['event' => $event->event, 'data' => $data]);
        });

        $stream->onBlockStop(function (StreamEvent $event) use ($sink, &$currentBlockSuppressed, &$visibleBlockCounter) {
            if ($currentBlockSuppressed) {
                $currentBlockSuppressed = false;

                return;
            }
            $data = $event->data;
            $data['block_index'] = $visibleBlockCounter;
            $sink(['event' => $event->event, 'data' => $data]);
            $visibleBlockCounter++;
        });

        $stream->onToolCall(function (string $toolName, array $params, string $callId) use ($sink) {
            $sink([
                'event' => MessageTypes::TOOL_CALL,
                'data' => [
                    'tool_name' => $toolName,
                    'parameters' => $params,
                    'tool_call_id' => $callId,
                    'call_id' => $callId, // Deprecated: use tool_call_id instead
                ],
            ]);
        });

        // What a tool returned. For a tool that ran on the operator's own
        // machine this is the only account of it there will ever be — and this
        // sink powers the documented streamToResponse() API, so leaving it
        // unwired meant half the fix reached one consumer and not the other.
        $stream->onToolResult(function (string $toolCallId, mixed $result, ?bool $isError = null, ?string $parentToolUseId = null) use ($sink) {
            $data = ['tool_call_id' => $toolCallId, 'result' => $result];
            if ($isError !== null) {
                $data['is_error'] = $isError;
            }
            if ($parentToolUseId !== null) {
                $data['parent_tool_use_id'] = $parentToolUseId;
            }
            $sink(['event' => MessageTypes::TOOL_RESULT, 'data' => $data]);
        });

        // Informational and non-terminal.
        $stream->onRateLimit(function (string $provider, array $info) use ($sink) {
            $sink(['event' => MessageTypes::RATE_LIMIT, 'data' => ['provider' => $provider, 'info' => $info]]);
        });

        // A file the assistant produced. Forwarded like any other non-terminal
        // event: the id is the app's own, so a consumer renders it from the
        // app's attachment store.
        $stream->onAttachment(function (array $attachment) use ($sink) {
            $sink(['event' => MessageTypes::ATTACHMENT, 'data' => $attachment]);
        });

        // A helper's life, forwarded whole, as BufferingSink buffers it.
        $stream->onTask(function (array $task) use ($sink) {
            $sink(['event' => MessageTypes::TASK, 'data' => $task]);
        });

        $stream->onDone(function (?array $usage, array $meta = []) use ($sink, $onTerminal) {
            // One allowlist, not two copies — a future field must be reviewed
            // once, not remembered in two places. cli_session_id is the
            // server's to keep; see BufferingSink for the reasoning.
            $meta = BufferingSink::publicDoneMeta($meta);

            // Wrap $sink() in try/finally so $onTerminal (the SSE [DONE] flush)
            // always runs even if the sink throws, preventing SSE clients from hanging.
            try {
                $sink(['event' => MessageTypes::DONE, 'data' => ['usage' => $usage] + $meta]);
            } finally {
                if ($onTerminal) {
                    $onTerminal();
                }
            }
        });

        $stream->onError(function (string $code, string $errorMessage) use ($sink, $onTerminal) {
            try {
                $sink(['event' => MessageTypes::ERROR, 'data' => ['code' => $code, 'message' => $errorMessage]]);
            } finally {
                if ($onTerminal) {
                    $onTerminal();
                }
            }
        });

        $stream->onCancelled(function (string $reason) use ($sink, $onTerminal) {
            try {
                $sink(['event' => MessageTypes::CANCELLED, 'data' => ['reason' => $reason]]);
            } finally {
                if ($onTerminal) {
                    $onTerminal();
                }
            }
        });
    }

    // ── Conversation scoping resolvers ────────────────────────────────

    /**
     * Project-supplied resolver returning the query of conversations visible
     * to the current request. The package owns no ownership concept — the
     * consuming app registers this in a service provider's boot().
     *
     * @var Closure|null
     */
    private ?Closure $conversationsResolver = null;

    /**
     * Project-supplied resolver returning the query of connections visible
     * to the current request.
     *
     * @var Closure|null
     */
    private ?Closure $connectionsResolver = null;

    /**
     * Register the resolver that scopes which conversations a request may see.
     *
     * The closure receives the current Request and returns an Eloquent
     * Builder for {@see \Tetrix\AiBridge\Models\Conversation}.
     */
    public function resolveConversationsUsing(Closure $resolver): static
    {
        $this->conversationsResolver = $resolver;

        return $this;
    }

    /**
     * Register the resolver that scopes which connections a request may see.
     */
    public function resolveConnectionsUsing(Closure $resolver): static
    {
        $this->connectionsResolver = $resolver;

        return $this;
    }

    /**
     * Get the scoped conversations query for a request.
     *
     * Secure-by-default: when the consuming app has not registered a resolver,
     * this returns an empty query so conversations are never exposed by accident.
     */
    public function conversationsQuery(\Illuminate\Http\Request $request): \Illuminate\Database\Eloquent\Builder
    {
        if ($this->conversationsResolver !== null) {
            return ($this->conversationsResolver)($request);
        }

        \Illuminate\Support\Facades\Log::warning('AI Bridge: no conversations resolver registered — denying all access. Call AiBridge::resolveConversationsUsing() in a service provider.');

        return \Tetrix\AiBridge\Models\Conversation::query()->whereRaw('1 = 0');
    }

    /**
     * Get the scoped connections query for a request.
     */
    public function connectionsQuery(\Illuminate\Http\Request $request): \Illuminate\Database\Eloquent\Builder
    {
        if ($this->connectionsResolver !== null) {
            return ($this->connectionsResolver)($request);
        }

        \Illuminate\Support\Facades\Log::warning('AI Bridge: no connections resolver registered — denying all access. Call AiBridge::resolveConnectionsUsing() in a service provider.');

        return \Tetrix\AiBridge\Models\Connection::query()->whereRaw('1 = 0');
    }

    // ── Attachment resolvers ──────────────────────────────────────────

    /** How many files one chat message may carry. */
    public const MAX_ATTACHMENTS_PER_TURN = 20;

    /**
     * Project-supplied lookup returning the file behind an attachment id.
     *
     * @var Closure|null
     */
    private ?Closure $attachmentResolver = null;

    /**
     * Project-supplied writer that stores a file the assistant sent back.
     *
     * @var Closure|null
     */
    private ?Closure $attachmentStore = null;

    /**
     * Register how an attachment id is turned into a file on disk.
     *
     * The package deliberately owns no file storage. Studio and `zeroplex/ai`
     * already have attachment stores of their own, and so will anything else
     * that uses this package — a storage opinion baked in here would have to
     * be fought in every consumer. Same pattern as the conversation and
     * connection resolvers above.
     *
     * The closure receives the attachment id and the user the bridge token was
     * issued for — **always as a string**, on both the build and the fetch
     * side, so a `===` comparison in the closure behaves the same in each —
     * and returns an SplFileInfo or null:
     *
     *   AiBridge::resolveAttachmentsUsing(
     *       fn (string $id, string $userId): ?\SplFileInfo => ...
     *   );
     *
     * **Scoping is the app's job and it matters.** The id comes from a bridge
     * that a person controls, so the lookup must be constrained to attachments
     * belonging to that user's own conversations — returning a file just
     * because the id exists would let any bridge fetch anyone's uploads.
     */
    public function resolveAttachmentsUsing(Closure $resolver): static
    {
        $this->attachmentResolver = $resolver;

        return $this;
    }

    /**
     * Register how a file the assistant produced is stored.
     *
     * The closure receives the uploaded file and the user the bridge token was
     * issued for, and returns at least an `id`, plus optionally `url`, `name`,
     * `mime_type` and `size`:
     *
     *   AiBridge::storeAttachmentUsing(
     *       fn (UploadedFile $file, string $userId): array => ['id' => ..., 'url' => ...]
     *   );
     */
    public function storeAttachmentUsing(Closure $store): static
    {
        $this->attachmentStore = $store;

        return $this;
    }

    /**
     * Resolve an attachment id to a file, scoped to the requesting bridge's user.
     *
     * Secure-by-default: with no resolver registered nothing is served, rather
     * than the package inventing a storage location and reading from it.
     */
    public function resolveAttachment(string $id, int|string $userId): ?\SplFileInfo
    {
        if ($this->attachmentResolver === null) {
            \Illuminate\Support\Facades\Log::warning('AI Bridge: no attachments resolver registered — denying all access. Call AiBridge::resolveAttachmentsUsing() in a service provider.');

            return null;
        }

        return ($this->attachmentResolver)($id, $userId);
    }

    /** Whether the app registered somewhere to put files the assistant sends back. */
    public function canStoreAttachments(): bool
    {
        return $this->attachmentStore !== null;
    }

    /**
     * Store a file the assistant sent back, returning at least its id.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When no store is registered, or it did not return an id.
     */
    public function storeAttachment(\Illuminate\Http\UploadedFile $file, int|string $userId): array
    {
        if ($this->attachmentStore === null) {
            throw new InvalidArgumentException(
                'No attachment store is registered. Call AiBridge::storeAttachmentUsing() in a service '
                .'provider to accept files the assistant sends back.'
            );
        }

        $result = ($this->attachmentStore)($file, $userId);

        if (! is_array($result) || ! isset($result['id'])) {
            throw new InvalidArgumentException(
                'The registered attachment store must return an array containing at least an "id".'
            );
        }

        return $result;
    }

    /**
     * Which user identifier this conversation's bridge is addressed by.
     *
     * The same resolution createBridgeProvider() uses to route the turn: a
     * managed connection's `connection_key`, else the authenticated user. It
     * has to be the same, because that value is the `sub` of the token the
     * bridge presents when it comes back to fetch an attachment — so scoping
     * the build with one identifier and the fetch with another produces
     * "attachment not found" for a file the requester owns.
     */
    public function bridgeUserIdFor(Conversation $conversation): ?string
    {
        $conversation->loadMissing('connection');
        $key = $conversation->connection?->connection_key;

        // `!== null && !== ''` rather than `! empty()`: a connection_key of
        // "0" is a perfectly good key and `empty()` calls it absent.
        $userId = ($key !== null && $key !== '') ? $key : $this->resolveAuthUserId();

        // Cast, because the fetch side reads the JWT `sub` and casts it to a
        // string. Handing the app's resolver an int here and a string there
        // makes a `===` comparison in that closure succeed at build time and
        // fail at fetch time: the turn validates, then the bridge gets a 404
        // for a file the user owns.
        return $userId === null ? null : (string) $userId;
    }

    /**
     * Turn attachment ids into the references an `ai_request` carries.
     *
     * The caller supplies ids, never URLs, and this builds the rest. That
     * asymmetry is deliberate: a URL taken from client input and handed to the
     * bridge would be a URL the bridge then fetches, with its own connection
     * token attached. The bridge already refuses anything off its server's
     * origin — but "any path on this application, authenticated as the bridge"
     * is still a wider door than anyone needs, and closing it here costs
     * nothing because the server knows its own attachment route.
     *
     * Size and SHA-256 are computed from the file the app's resolver returns,
     * so the bridge's verification is checking the bytes against the same file
     * the server would have served, rather than against a claim that travelled
     * alongside them.
     *
     * @param  array<int, mixed>  $ids  Attachment ids from the request.
     * @param  string  $userId  The user the bridge's token is issued for, always as a string.
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException When an id does not resolve to a readable file.
     */
    public function buildAttachmentRefs(array $ids, ?string $userId): array
    {
        // Refuse rather than scope by the empty string. An app resolver that
        // filters by owner finds nothing for '' and answers "not found", which
        // reads as a broken attachment rather than as a turn with nobody to
        // attribute it to.
        if ($userId === null || $userId === '') {
            throw new InvalidArgumentException(
                'Cannot attach files: this conversation has no bridge user to scope them to. '
                .'Link the conversation to a connection, or call from an authenticated request.'
            );
        }

        // Each id costs a resolver call and a full file hash, in one request.
        // A chat message carries a handful of files; a list of ten thousand is
        // a mistake or an attack, and either way should fail immediately.
        if (count($ids) > self::MAX_ATTACHMENTS_PER_TURN) {
            throw new InvalidArgumentException(
                'A message may carry at most '.self::MAX_ATTACHMENTS_PER_TURN.' attachments.'
            );
        }

        $refs = [];

        foreach ($ids as $id) {
            if (! is_string($id) && ! is_int($id)) {
                throw new InvalidArgumentException('Each attachment must be an id.');
            }
            $id = (string) $id;

            // The same charset the attachment route accepts. An app whose ids
            // are base64 or composite keys would otherwise build a URL that
            // 404s at fetch time, with nothing having failed here.
            if (! preg_match('/^[A-Za-z0-9._-]+$/', $id) || trim($id, '.') === '') {
                // The trim also refuses "." and "..", which the charset above
                // permits and which an app resolver doing
                // `storage_path("attachments/$id")` would happily turn into a
                // directory. Refusing them here costs nothing.
                throw new InvalidArgumentException(
                    "Attachment id \"{$id}\" is not a usable identifier "
                    .'(allowed: letters, digits, dot, underscore, hyphen; not "." or "..").'
                );
            }

            $file = $this->resolveAttachment($id, $userId);

            if ($file === null || ! $file->isFile() || ! $file->isReadable()) {
                throw new InvalidArgumentException("Attachment \"{$id}\" was not found.");
            }

            $digest = hash_file('sha256', $file->getPathname());
            if ($digest === false) {
                // The file went away between resolving it and hashing it.
                // Sending an empty digest would fail on the bridge as a
                // checksum mismatch, which points at the wrong problem.
                throw new InvalidArgumentException("Attachment \"{$id}\" could not be read.");
            }

            $refs[] = [
                'id' => $id,
                'name' => $file->getFilename(),
                'mime_type' => $this->guessMimeType($file),
                'size' => (int) $file->getSize(),
                'sha256' => $digest,
                'url' => $this->attachmentUrl($id),
            ];
        }

        return $refs;
    }

    /**
     * The absolute URL the bridge should fetch an attachment from.
     *
     * Built from the CONFIGURED application URL, not from `url()`. `url()`
     * takes its scheme and host from the current request, which makes this a
     * value an end user can influence with a `Host:` header — and the bridge
     * fetches whatever it is given with its own connection token attached.
     * Laravel does not validate Host by default (`trustHosts()` is opt-in), so
     * relying on the request here would hand a token-bearing fetch to whatever
     * host the caller named. The bridge's own origin check is the backstop;
     * this is the part the server is supposed to get right.
     *
     * It also fixes the quieter failure: behind a TLS-terminating proxy without
     * TrustProxies configured, `url()` emits `http://` and the bridge refuses
     * every attachment for being non-HTTPS, two hops from the cause.
     *
     * Note that this must be an origin the bridge accepts — the one it derives
     * from `--server`, or the one the operator passed as `--api`.
     */
    private function attachmentUrl(string $id): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base.'/ai-bridge/attachments/'.rawurlencode($id);
    }

    /**
     * Best guess at an attachment's content type.
     *
     * The model uses this to decide how to read the file, and the CLI's own
     * file tools do too, so a wrong answer is worse than a vague one —
     * `application/octet-stream` is the honest fallback.
     */
    private function guessMimeType(\SplFileInfo $file): string
    {
        if ($file instanceof \Symfony\Component\HttpFoundation\File\File) {
            $guessed = $file->getMimeType();
            if (is_string($guessed) && $guessed !== '') {
                return $guessed;
            }
        }

        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $guessed = $finfo->file($file->getPathname());
            if (is_string($guessed) && $guessed !== '') {
                return $guessed;
            }
        }

        return 'application/octet-stream';
    }

    /**
     * Get the active provider mode from config.
     *
     * @throws InvalidArgumentException If the configured mode is not a valid ProviderMode value.
     */
    public function mode(): ProviderMode
    {
        $modeValue = config('ai-bridge.mode', 'byok');

        try {
            return ProviderMode::from($modeValue);
        } catch (\ValueError) {
            $allowed = implode(', ', array_map(fn (ProviderMode $m) => "'{$m->value}'", ProviderMode::cases()));
            throw new InvalidArgumentException(
                "Invalid AI_BRIDGE_MODE value '{$modeValue}'. Allowed values: {$allowed}."
            );
        }
    }

    /**
     * Resolve the provider mode from configuration.
     *
     * Mode is always determined server-side from config — never from request input.
     * The $options parameter accepts ProviderMode enum instances for programmatic
     * override only (e.g. when the consuming app explicitly passes a mode).
     */
    private function resolveMode(array $options): ProviderMode
    {
        // Only accept ProviderMode enum instances for programmatic override,
        // not arbitrary string values from request input.
        if (isset($options['mode']) && $options['mode'] instanceof ProviderMode) {
            return $options['mode'];
        }

        return $this->mode();
    }

    /**
     * Create the appropriate StreamableProvider for the given mode.
     *
     * @throws InvalidArgumentException If required configuration is missing.
     */
    private function createProvider(ProviderMode $mode, array $options): StreamableProvider
    {
        return match ($mode) {
            ProviderMode::Bridge => $this->createBridgeProvider($options),
            ProviderMode::Byok, ProviderMode::Managed => $this->createChatCompletionsProvider($options),
        };
    }

    /**
     * Create a BridgeStream provider.
     */
    private function createBridgeProvider(array $options): BridgeStream
    {
        // SEC: user_id is stripped from HTTP request input by StreamController.
        // Programmatic callers (jobs, artisan commands) can pass user_id via options.
        $userId = $options['user_id'] ?? $this->resolveAuthUserId();

        if ($userId === null) {
            throw new InvalidArgumentException(
                'Bridge mode requires an authenticated user or a "user_id" option.'
            );
        }

        $stream = new BridgeStream(
            $this->connectionManager,
            $this->toolRegistry,
            $this->tokenManager,
            $userId,
        );

        // Set the provider name for routing on the bridge side. Provider selection
        // is done via the $options['provider'] key at call time; the
        // 'ai-bridge.bridge.provider' config key is an undocumented fallback.
        $provider = $options['provider'] ?? config('ai-bridge.bridge.provider', '');
        if (! empty($provider)) {
            $stream->setProvider($provider);
        }

        return $stream;
    }

    /**
     * Create a ChatCompletionsStream provider.
     */
    private function createChatCompletionsProvider(array $options): ChatCompletionsStream
    {
        // endpoint and api_key may be overridden programmatically (e.g. per-conversation
        // BYOK, where streamConversation() resolves them from a server-side Connection
        // row). The StreamController strips both from HTTP request input, so only
        // server-side callers can set them — guarding against SSRF / key injection.
        $endpoint = $options['endpoint'] ?? config('ai-bridge.chat_completions.endpoint');
        $apiKey = $options['api_key'] ?? config('ai-bridge.chat_completions.api_key');
        $model = $options['model'] ?? config('ai-bridge.chat_completions.model');
        $maxTokens = $options['max_tokens'] ?? (int) config('ai-bridge.chat_completions.max_tokens', 4096);

        if (empty($endpoint)) {
            throw new InvalidArgumentException(
                'Chat Completions endpoint is not configured. Set AI_BRIDGE_ENDPOINT in .env (e.g. https://api.openai.com), or pass endpoint via config.'
            );
        }

        if (empty($apiKey)) {
            throw new InvalidArgumentException(
                'Chat Completions API key is not configured. Set AI_BRIDGE_API_KEY in .env, or pass api_key in the options array for per-user BYOK.'
            );
        }

        if (empty($model)) {
            throw new InvalidArgumentException(
                'Chat Completions model is not configured. Set AI_BRIDGE_MODEL in .env (e.g. gpt-4o).'
            );
        }

        return new ChatCompletionsStream(
            $this->toolRegistry,
            $endpoint,
            $apiKey,
            $model,
            (int) $maxTokens,
        );
    }

    /**
     * Resolve the authenticated user's ID from the request context.
     */
    private function resolveAuthUserId(): int|string|null
    {
        $user = auth()->user();

        return $user?->getAuthIdentifier();
    }
}
