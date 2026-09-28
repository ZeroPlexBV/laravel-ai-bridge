<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Protocol\AiRequestPayload;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Support\BridgeLog;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/**
 * Streams AI responses through a CLI bridge connected via WebSocket.
 *
 * When the consuming app calls start(), this class:
 * 1. Looks up the user's active bridge connection
 * 2. Sends an ai_request message over the WebSocket
 * 3. Registers the request as pending so incoming events can be routed
 *
 * Note: BridgeStream is inherently asynchronous. Unlike ChatCompletionsStream which
 * blocks during start() until the HTTP SSE stream completes, BridgeStream's start()
 * returns immediately after sending the ai_request. Events arrive asynchronously via
 * the WebSocket server and are dispatched to the StreamHandler by the MessageHandler.
 *
 * ## PHP-FPM vs Long-Running Process
 *
 * When running under a long-running process (e.g. the bridge WebSocket server itself,
 * Octane, or Swoole), BridgeConnectionManager holds connections in-memory and start()
 * can send directly via the WebSocket.
 *
 * Under PHP-FPM (shared-nothing model), the BridgeConnectionManager is empty because
 * the WebSocket server runs in a separate process. In this case, BridgeStream falls
 * back to relaying the request through the bridge server's internal HTTP API
 * (POST /api/request on the configured server port). The response events arrive
 * asynchronously at the serve process and are buffered to the stream-event store
 * there; the browser tails the buffer via SSE.
 */
class BridgeStream implements StreamableProvider
{
    private string $conversationId = '';

    private string $message = '';

    /** @var array<string, mixed> */
    private array $options = [];

    private StreamHandler $streamHandler;

    private bool $cancelled = false;

    /**
     * Whether the stream has completed (done or error received).
     * Useful for the consuming app to check completion state in async scenarios.
     */
    private bool $completed = false;

    /** The provider name to route to on the bridge (e.g. 'claude', 'codex', 'gemini'). */
    private string $provider = '';

    public function __construct(
        private readonly BridgeConnectionManager $connectionManager,
        private readonly ToolRegistry $toolRegistry,
        private readonly TokenManager $tokenManager,
        private readonly int|string $userId,
    ) {
        $this->streamHandler = new StreamHandler($this);
    }

    public function setConversationId(string $conversationId): static
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Set the provider name for routing on the bridge side.
     *
     * @param  string  $provider  The provider name (e.g. 'claude', 'codex', 'gemini').
     */
    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getStreamHandler(): StreamHandler
    {
        return $this->streamHandler;
    }

    /**
     * Check whether the stream has completed (done or error received).
     */
    public function isCompleted(): bool
    {
        return $this->completed;
    }

    /**
     * Mark the stream as completed.
     *
     * @internal Called by the StreamHandler when done or error is dispatched.
     */
    public function markCompleted(): void
    {
        $this->completed = true;
    }

    /**
     * Build the ai_request payload to send to the bridge.
     *
     * The shape itself lives in AiRequestPayload, which the PHP-FPM relay path
     * (BridgeWebSocketServer::apiRequest()) also calls. This method's job is
     * only to turn THIS class's inputs into that builder's arguments — so a new
     * protocol field is added in one place and both paths get it.
     *
     * @return array<string, mixed>
     */
    public function buildRequestBody(): array
    {
        return AiRequestPayload::build([
            'request_id' => $this->streamHandler->requestId,
            'conversation_id' => $this->conversationId,
            'provider' => $this->provider,
            'message' => $this->message,
            'system_prompt' => $this->options['system_prompt'] ?? null,
            'options' => [
                'temperature' => $this->options['temperature'] ?? null,
                'max_tokens' => $this->options['max_tokens'] ?? null,
                'model' => $this->options['model'] ?? null,
                // Keep the turn's input open so a message can reach it while
                // it runs (AiBridge::sendTurnInput). Opt-in per turn; the
                // bridge confirms it with `input_open` on the ack.
                'accepts_input' => ($this->options['accepts_input'] ?? null) === true ? true : null,
            ],
            'cli_session_id' => $this->options['cli_session_id'] ?? null,
            'history' => $this->options['messages'] ?? null,
            // Tools this conversation exposes (null = all registered).
            'tools' => $this->toolRegistry->toArray($this->options['allowed_tools'] ?? null),
            // Where the assistant should work. Persisted on the conversation by
            // the caller, so a resumed turn sends the same one — the bridge
            // refuses a resume that names somewhere else.
            'working_dir' => $this->options['working_dir'] ?? null,
            'attachments' => $this->options['attachments'] ?? null,
        ]);
    }

    /**
     * Send the ai_request to the bridge and register as pending.
     *
     * Under PHP-FPM (when WebSocket server runs as separate process), this method
     * blocks for up to relay_timeout seconds while the internal HTTP relay completes.
     * True non-blocking behavior only applies to long-running server deployments
     * (Octane, bridge server itself) where BridgeConnectionManager holds connections
     * in-memory and sendToUser() returns immediately.
     *
     * NOTE: Under the PHP-FPM relay path, response events arrive
     * asynchronously at the long-running serve process; they are written
     * to the per-turn stream-event buffer there. The web caller can hand
     * the request_id back to the browser, which tails the buffer over
     * SSE for the actual reply.
     */
    public function start(): void
    {
        $this->cancelled = false;
        $this->completed = false;

        $payload = $this->buildRequestBody();

        // Try direct WebSocket send first (works in long-running processes like
        // the bridge server itself, Octane, or Swoole where connections are in-memory).
        if ($this->connectionManager->hasConnection($this->userId)) {
            $sent = $this->connectionManager->sendToUser($this->userId, $payload);

            if (! $sent) {
                $this->streamHandler->dispatchError(
                    'bridge_send_failed',
                    'Failed to send request to bridge.'
                );

                return;
            }

            // Register this stream as a pending request so incoming WebSocket
            // messages can be routed to the correct StreamHandler.
            $this->connectionManager->registerPendingRequest(
                $this->streamHandler->requestId,
                $this->streamHandler,
                (string) $this->userId,
            );

            return;
        }

        // Fallback: relay through the bridge server's internal HTTP API.
        // This is the PHP-FPM path — the ConnectionManager is empty because
        // the WebSocket server runs in a separate process.
        $this->relayViaHttpApi($payload);
    }

    /**
     * Relay an ai_request through the bridge server's internal HTTP API.
     *
     * Used under PHP-FPM where the WebSocket server is a separate process.
     * The internal API at POST /api/request accepts the request and forwards
     * it to the connected bridge client.
     *
     * NOTE: Response events arrive asynchronously at the serve process via
     * the WebSocket server, where RelayStream's BufferingSink writes them
     * to the per-turn stream-event buffer the browser tails over SSE.
     */
    private function relayViaHttpApi(array $payload): void
    {
        // Operators can configure HTTPS for the internal relay when the bridge
        // server runs on a separate host or behind a TLS proxy.
        $configuredUrl = config('ai-bridge.server.relay_url');

        if (! empty($configuredUrl)) {
            $url = rtrim($configuredUrl, '/') . '/api/request';

            // A configured http:// relay URL exposes the internal relay token in
            // cleartext unless the host is loopback — warn so operators switch to https://.
            $configuredHost = parse_url($configuredUrl, PHP_URL_HOST) ?: '';
            if (str_starts_with(strtolower($configuredUrl), 'http://') && ! $this->isLoopbackHost($configuredHost)) {
                // Use BridgeLog (logging failures are swallowed) rather than
                // the Log facade. start() is wrapped by
                // AiBridgeManager::startAfterResponse() which surfaces ANY
                // exception as "The request could not be started" — including
                // a Monolog write failure on this informational warning.
                // Losing a log line is always preferable to killing the turn.
                BridgeLog::warning(
                    'AI_BRIDGE_RELAY_URL uses plaintext http:// for a non-loopback host. '
                    .'The internal relay token is transmitted unencrypted. Use an https:// URL when the '
                    .'bridge server runs on a separate host.',
                    ['host' => $configuredHost]
                );
            }
        } else {
            $host = config('ai-bridge.server.host', '127.0.0.1');
            $port = (int) config('ai-bridge.server.port', 8085);

            // Use 127.0.0.1 for localhost connections (0.0.0.0 is a listen address, not connectable)
            if ($host === '0.0.0.0') {
                $host = '127.0.0.1';
            }

            // The fallback relay URL is always plaintext http:// — safe for
            // loopback hosts, but on a non-loopback host the internal relay token
            // is sent in cleartext. Warn so operators set AI_BRIDGE_RELAY_URL.
            if (! $this->isLoopbackHost($host)) {
                // See note on the previous BridgeLog::warning call about why we
                // do not use the Log facade in start()'s call path.
                BridgeLog::warning(
                    'internal relay falls back to plaintext http:// for a non-loopback bridge host. '
                    .'The internal relay token is transmitted unencrypted. Set AI_BRIDGE_RELAY_URL to an https:// '
                    .'URL when the bridge server runs on a separate host.',
                    ['host' => $host, 'port' => $port]
                );
            }

            $url = "http://{$host}:{$port}/api/request";
        }

        try {
            // Use a short-lived token with the internal_relay scope to distinguish
            // internal relay requests from user-facing bridge connection tokens.
            $internalToken = $this->tokenManager->generate($this->userId, ['scope' => TokenManager::INTERNAL_RELAY_SCOPE], 60);

            $relayBody = [
                'request_id' => $payload['request_id'] ?? '',
                'message' => $payload['message'] ?? '',
                'conversation_id' => $payload['conversation_id'] ?? '',
                'options' => $payload['options'] ?? [],
                'tools' => $payload['tools'] ?? [],
            ];

            // Only include provider when non-empty — the bridge server's apiRequest()
            // requires this field to be non-empty if present. Omit it when not set so
            // the bridge can fall back to its configured default.
            if (! empty($payload['provider'])) {
                $relayBody['provider'] = $payload['provider'];
            }

            if (isset($payload['system_prompt'])) {
                $relayBody['system_prompt'] = $payload['system_prompt'];
            }

            // The CLI session to resume (null = fresh). Always forwarded.
            $relayBody['cli_session_id'] = $payload['cli_session_id'] ?? null;

            // Prior history — present only for a fresh session (see buildRequestBody()).
            if (isset($payload['history'])) {
                $relayBody['history'] = $payload['history'];
            }

            // Forwarded so the relay path reaches the bridge with the same
            // frame the direct path would have sent. Omitted when absent,
            // matching AiRequestPayload's convention: the relay rebuilds the
            // payload through that same builder at the other end.
            if (isset($payload['working_dir'])) {
                $relayBody['working_dir'] = $payload['working_dir'];
            }

            if (isset($payload['attachments'])) {
                $relayBody['attachments'] = $payload['attachments'];
            }

            BridgeLog::verbose('relay request payload', [
                'request_id' => $relayBody['request_id'],
                'url' => $url,
                'provider' => $relayBody['provider'] ?? null,
                'message' => $relayBody['message'],
                'options' => $relayBody['options'],
                'tools_count' => count($relayBody['tools']),
            ]);

            $response = Http::withToken($internalToken)
                ->timeout((int) config('ai-bridge.server.relay_timeout', 5))
                ->post($url, $relayBody);

            if ($response->failed()) {
                $body = $response->json();
                $error = $body['error'] ?? 'unknown';

                BridgeLog::error('bridge server returned error', [
                    'request_id' => $payload['request_id'] ?? '',
                    'status' => $response->status(),
                    'error' => $error,
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                $this->streamHandler->dispatchError(
                    $error,
                    'Bridge server returned HTTP '.$response->status().'. Check server logs for details.'
                );

                return;
            }

            // Request was accepted by the bridge server. Events will arrive
            // asynchronously at the serve process, where RelayStream's
            // BufferingSink writes them to the per-turn buffer the browser
            // tails over SSE.
            BridgeLog::info('relayed request to bridge server (events buffered in serve process)', [
                'request_id' => $payload['request_id'] ?? '',
                'user_id' => $this->userId,
                'url' => $url,
                'conversation_id' => $payload['conversation_id'] ?? '',
            ]);

        } catch (\Exception $e) {
            BridgeLog::error('failed to relay request to bridge server', [
                'request_id' => $payload['request_id'] ?? '',
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            // Categorize the error for more actionable user-facing messages
            $userMessage = str_contains($e->getMessage(), 'timed out')
                ? 'Bridge server did not respond within the timeout period.'
                : (str_contains($e->getMessage(), 'Connection refused')
                    ? 'Could not connect to bridge server. Is it running?'
                    : 'Failed to relay request to bridge server. Check server logs for details.');

            $this->streamHandler->dispatchError('bridge_relay_failed', $userMessage);
        }
    }

    /**
     * Determine whether a host refers to the local loopback interface.
     *
     * Used to decide when a plaintext http:// relay path is safe — loopback
     * traffic never leaves the machine.
     */
    private function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === '' || $host === 'localhost' || $host === '::1') {
            return true;
        }

        // Any address in 127.0.0.0/8 is loopback.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.');
        }

        return false;
    }

    public function cancel(): void
    {
        $this->cancelled = true;

        // Send cancel message to bridge.
        // Don't remove pending request here — the bridge responds with a 'cancelled'
        // message, and handleCancelled() needs the pending request to dispatch the
        // terminal error event before cleaning up.
        $sent = $this->connectionManager->sendToUser($this->userId, [
            'type' => MessageTypes::CANCEL,
            'request_id' => $this->streamHandler->requestId,
        ]);

        // If the cancel message couldn't be sent (connection gone), dispatch error
        // and clean up the pending request since we won't receive a 'cancelled' response.
        if (! $sent) {
            $this->streamHandler->dispatchError(
                'cancel_send_failed',
                'Failed to send cancel to bridge — connection may be lost.'
            );
            $this->connectionManager->removePendingRequest($this->streamHandler->requestId);
        }
    }
}
