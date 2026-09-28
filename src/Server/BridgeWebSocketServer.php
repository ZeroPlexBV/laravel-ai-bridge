<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Server;

use Illuminate\Support\Facades\Log;
use GuzzleHttp\Psr7\Message;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Protocol\AiRequestPayload;
use Tetrix\AiBridge\Support\BridgeLog;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/**
 * Dedicated WebSocket server for CLI bridge connections.
 *
 * This is NOT Laravel Reverb — it is a separate, lightweight server on its
 * own port that speaks the AI Bridge Protocol. It handles connections from
 * `npx @tetrixdev/ai-bridge` clients.
 *
 * Uses react/socket + ratchet/rfc6455 under the hood — the same stack
 * Laravel Reverb uses internally. Does NOT depend on cboden/ratchet.
 */
class BridgeWebSocketServer
{
    /**
     * Pre-upgrade buffering caps. The HTTP header section is bounded tightly to
     * stop a header flood; a request body (e.g. a relayed conversation seed,
     * which can legitimately be large) is bounded by its declared Content-Length
     * up to this generous maximum.
     */
    private const MAX_HEADER_BYTES = 65536;          // 64 KB

    private const MAX_REQUEST_BODY_BYTES = 16777216; // 16 MB

    private ?LoopInterface $loop = null;

    private ?SocketServer $socket = null;

    /**
     * Auto-incrementing resource ID counter for connections.
     */
    private int $nextResourceId = 1;

    /**
     * The server negotiator for WebSocket handshakes.
     */
    private ?ServerNegotiator $negotiator = null;

    public function __construct(
        private readonly BridgeConnectionManager $connectionManager,
        private readonly MessageHandler $messageHandler,
        private readonly TokenManager $tokenManager,
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 8085,
    ) {}

    /**
     * Start the WebSocket server.
     *
     * This method blocks — it runs the ReactPHP event loop until shutdown.
     *
     * TODO: Heartbeat watchdog is not yet implemented — the server should close
     * connections that have not sent a ping within 2 × heartbeat_interval.
     *
     * @param  callable|null  $onStart  Callback invoked after the server starts listening.
     */
    public function start(?callable $onStart = null): void
    {
        $this->loop = Loop::get();

        $this->negotiator = new ServerNegotiator(new RequestVerifier(), new HttpFactory());

        $handler = new BridgeWebSocketHandler(
            $this->connectionManager,
            $this->messageHandler,
            $this->tokenManager,
        );

        $this->socket = new SocketServer("{$this->host}:{$this->port}", [], $this->loop);

        $this->socket->on('connection', function (ConnectionInterface $tcpConnection) use ($handler) {
            $this->handleTcpConnection($tcpConnection, $handler);
        });

        // Periodically top up long-lived bridge tokens. A bridge that stays
        // connected without ever re-running the handshake would otherwise let
        // its token lapse; this slow timer (default every 12h — far slower than
        // the heartbeat) re-issues tokens that are past half their life.
        $refreshInterval = (int) config('ai-bridge.token.refresh_check_interval', 43200);
        if ($refreshInterval > 0) {
            $this->loop->addPeriodicTimer($refreshInterval, fn () => $this->refreshAgingTokens());
        }

        if ($onStart !== null) {
            // Schedule the callback to run after the loop starts
            $this->loop->futureTick($onStart);
        }

        $this->loop->run();
    }

    /**
     * Handle a new raw TCP connection.
     *
     * Buffers the initial HTTP request data, performs the WebSocket upgrade
     * handshake using ratchet/rfc6455's ServerNegotiator, then sets up a
     * MessageBuffer to parse incoming WebSocket frames.
     */
    private function handleTcpConnection(ConnectionInterface $tcpConnection, BridgeWebSocketHandler $handler): void
    {
        $httpBuffer = '';
        $upgraded = false;
        $headersComplete = false;
        $expectedBodyLength = 0;
        $headerLength = 0;

        // Store a reference to the HTTP-buffering closure so we can remove it
        // after the WebSocket upgrade — otherwise it fires on every subsequent frame.
        $httpListener = null;
        $httpListener = function (string $data) use ($tcpConnection, $handler, &$httpBuffer, &$upgraded, &$headersComplete, &$expectedBodyLength, &$headerLength, &$httpListener) {
            // If already upgraded, data is handled by the MessageBuffer (attached below).
            // This branch should never fire after upgradeConnection() removes the listener,
            // but the guard remains as a safety net.
            if ($upgraded) {
                return;
            }

            $httpBuffer .= $data;

            // Phase 1: wait for headers to complete (\r\n\r\n)
            if (! $headersComplete) {
                $headerEnd = strpos($httpBuffer, "\r\n\r\n");
                if ($headerEnd === false) {
                    // SEC: bound the header section to stop a header flood before
                    // we even know what this connection is (the body limit below
                    // covers the request body once Content-Length is known).
                    if (strlen($httpBuffer) > self::MAX_HEADER_BYTES) {
                        $this->httpResponse($tcpConnection, 413, ['error' => 'payload_too_large']);
                    }

                    return;
                }

                $headersComplete = true;
                $headerLength = $headerEnd + 4;

                // Extract Content-Length from headers to know how much body to expect
                $headerSection = substr($httpBuffer, 0, $headerEnd);
                if (preg_match('/^Content-Length:\s*(\d+)/im', $headerSection, $m)) {
                    $expectedBodyLength = (int) $m[1];
                }

                // SEC: reject an over-large declared body up front. A relayed
                // conversation seed can be large (hundreds of KB), but bound it.
                if ($expectedBodyLength > self::MAX_REQUEST_BODY_BYTES) {
                    $this->httpResponse($tcpConnection, 413, ['error' => 'payload_too_large']);

                    return;
                }
            }

            // Phase 2: wait for the full body (if any)
            $receivedBodyLength = strlen($httpBuffer) - $headerLength;
            if ($receivedBodyLength < $expectedBodyLength) {
                return;
            }

            $upgraded = true;

            // Parse the raw HTTP request into a PSR-7 request
            try {
                $request = Message::parseRequest($httpBuffer);
            } catch (\Throwable) {
                $tcpConnection->write("HTTP/1.1 400 Bad Request\r\nContent-Length: 12\r\n\r\nBad Request\n");
                $tcpConnection->end();

                return;
            }

            $httpBuffer = '';

            // Check if this is a WebSocket upgrade request or a plain HTTP request
            if (! $this->isWebSocketUpgrade($request)) {
                $this->handleHttpRequest($tcpConnection, $request);

                return;
            }

            $this->upgradeConnection($tcpConnection, $request, $handler, $httpListener);
        };

        $tcpConnection->on('data', $httpListener);
    }

    /**
     * Attempt a WebSocket upgrade on the given TCP connection.
     *
     * If the handshake succeeds, writes the 101 response to the stream, creates
     * a BridgeConnection wrapper and MessageBuffer, then notifies the handler.
     *
     * @param  callable|null  $httpListener  The HTTP-buffering data listener to remove after upgrade.
     */
    private function upgradeConnection(
        ConnectionInterface $tcpConnection,
        RequestInterface $request,
        BridgeWebSocketHandler $handler,
        ?callable $httpListener = null,
    ): void {
        // Perform the WebSocket upgrade handshake
        $response = $this->negotiator->handshake($request);

        if ($response->getStatusCode() !== 101) {
            $tcpConnection->write(Message::toString($response));
            $tcpConnection->end();

            return;
        }

        // Write the 101 Switching Protocols response to complete the upgrade
        $tcpConnection->write(Message::toString($response));

        // Remove the HTTP-buffering listener now that the WebSocket upgrade is
        // complete, so it does not fire on every subsequent WebSocket frame.
        if ($httpListener !== null) {
            $tcpConnection->removeListener('data', $httpListener);
        }

        $resourceId = $this->nextResourceId++;
        $queryString = $request->getUri()->getQuery();
        $authorizationHeader = $request->getHeaderLine('Authorization');

        // Guard against duplicate onClose calls (close frame + TCP close event)
        $closed = false;
        $doClose = function () use ($handler, &$bridgeConnection, &$closed) {
            if ($closed) {
                return;
            }
            $closed = true;
            $handler->onClose($bridgeConnection);
        };

        // Create a BridgeConnection wrapper with a send callback that encodes
        // outgoing messages into WebSocket frames before writing to the stream.
        $bridgeConnection = new BridgeConnection(
            resourceId: $resourceId,
            stream: $tcpConnection,
            sendCallback: function (string $data) use ($tcpConnection) {
                $frame = new Frame($data);
                $tcpConnection->write($frame->getContents());
            },
        );

        // Set up the MessageBuffer to parse incoming WebSocket frames.
        // The buffer calls our onMessage handler when a complete message arrives,
        // and handles control frames (ping/pong/close) automatically.
        // Cap message size at 1 MB to prevent memory exhaustion from oversized
        // bridge messages.
        $maxMessagePayloadSize = 1024 * 1024; // 1 MB
        $messageBuffer = new MessageBuffer(
            new CloseFrameChecker(),
            onMessage: function (MessageInterface $message) use ($handler, $bridgeConnection) {
                $handler->onMessage($bridgeConnection, (string) $message->getPayload());
            },
            onControl: function (FrameInterface $frame) use ($tcpConnection, $doClose, $bridgeConnection) {
                match ($frame->getOpcode()) {
                    Frame::OP_PING => $tcpConnection->write(
                        (new Frame($frame->getPayload(), opcode: Frame::OP_PONG))->getContents()
                    ),
                    Frame::OP_CLOSE => (function () use ($tcpConnection, $doClose, $frame) {
                        // Echo the close frame back per RFC 6455
                        $tcpConnection->write(
                            (new Frame($frame->getPayload(), opcode: Frame::OP_CLOSE))->getContents()
                        );
                        $doClose();
                        $tcpConnection->end();
                    })(),
                    default => null,
                };
            },
            maxMessagePayloadSize: $maxMessagePayloadSize,
            sender: function (string $data) use ($tcpConnection) {
                $tcpConnection->write($data);
            },
        );

        // Route incoming TCP data through the MessageBuffer for frame parsing
        $tcpConnection->on('data', [$messageBuffer, 'onData']);

        // Handle TCP connection close (e.g. client disconnects without close frame)
        $tcpConnection->on('close', $doClose);

        // Handle TCP errors
        $tcpConnection->on('error', function (\Exception $e) use ($handler, $bridgeConnection) {
            $handler->onError($bridgeConnection, $e);
        });

        // Notify the handler that the WebSocket connection is open.
        // Pass the Authorization header so the handler can prefer it over the query param.
        $handler->onOpen($bridgeConnection, $queryString, $authorizationHeader);
    }

    /**
     * Stop the server gracefully.
     */
    public function stop(): void
    {
        if ($this->socket !== null) {
            $this->socket->close();
        }

        if ($this->loop !== null) {
            $this->loop->stop();
        }
    }

    /**
     * Get the event loop instance (available after start() is called).
     */
    public function getLoop(): ?LoopInterface
    {
        return $this->loop;
    }

    /**
     * Get the host address.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Get the port number.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Re-issue connection tokens for bridges whose token is past half its life.
     *
     * Driven by the periodic timer set up in start(). Delegates the half-life
     * decision to MessageHandler::maybeRefreshToken() — the same logic used at
     * the handshake — and pushes any fresh token to the bridge as a
     * `token_refresh` message.
     */
    private function refreshAgingTokens(): void
    {
        foreach ($this->connectionManager->connectedUserIds() as $userId) {
            $token = $this->messageHandler->maybeRefreshToken($userId);

            if ($token !== null) {
                $this->connectionManager->sendToUser($userId, [
                    'type' => MessageTypes::TOKEN_REFRESH,
                    'token' => $token,
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Internal HTTP API
    // -------------------------------------------------------------------------
    // The bridge server accepts plain HTTP requests alongside WebSocket
    // connections. This allows the web app (PHP-FPM) to communicate with
    // connected bridge clients through the same port.
    //
    // Endpoints:
    //   POST /api/request    — Send an ai_request to a user's bridge
    //   POST /api/request/input — Send a message into a user's running turn
    //   POST /api/disconnect — Forcibly drop a user's bridge connection
    //   GET  /api/status     — Check connected users
    //   GET  /api/health     — Health check
    // -------------------------------------------------------------------------

    /**
     * Check whether the HTTP request is a WebSocket upgrade.
     */
    private function isWebSocketUpgrade(RequestInterface $request): bool
    {
        $upgrade = strtolower($request->getHeaderLine('Upgrade'));

        return $upgrade === 'websocket';
    }

    /**
     * Handle a plain HTTP request (non-WebSocket).
     *
     * Routes to internal API endpoints for inter-process communication.
     * Validates Bearer token authentication on protected endpoints.
     */
    private function handleHttpRequest(ConnectionInterface $tcpConnection, RequestInterface $request): void
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        // Health check — no auth required. Return only a minimal status string;
        // do not expose connection count or other operational metrics.
        if ($method === 'GET' && $path === '/api/health') {
            $this->httpResponse($tcpConnection, 200, [
                'status' => 'ok',
            ]);

            return;
        }

        // Validate Bearer token for protected endpoints
        $authHeader = $request->getHeaderLine('Authorization');
        if (! str_starts_with($authHeader, 'Bearer ')) {
            $this->httpResponse($tcpConnection, 401, [
                'error' => 'missing_token',
                'message' => 'Authorization header with Bearer token required.',
            ]);

            return;
        }

        $token = substr($authHeader, 7);

        try {
            // Require the internal_relay scope so user-facing bridge tokens
            // cannot be used to call the internal HTTP API.
            $decoded = $this->tokenManager->validate($token, TokenManager::INTERNAL_RELAY_SCOPE);
        } catch (\Throwable $e) {
            $this->httpResponse($tcpConnection, 401, [
                'error' => 'invalid_token',
                'message' => $e->getMessage(),
            ]);

            return;
        }

        // Route to endpoints
        match (true) {
            $method === 'GET' && $path === '/api/status' => $this->apiStatus($tcpConnection, $decoded),
            $method === 'POST' && $path === '/api/request' => $this->apiRequest($tcpConnection, $request, $decoded),
            $method === 'POST' && $path === '/api/request/input' => $this->apiRequestInput($tcpConnection, $request, $decoded),
            $method === 'GET' && $path === '/api/usage' => $this->apiUsage($tcpConnection, $request, $decoded),
            $method === 'POST' && $path === '/api/disconnect' => $this->apiDisconnect($tcpConnection, $decoded),
            default => $this->httpResponse($tcpConnection, 404, [
                'error' => 'not_found',
                'message' => "Unknown endpoint: {$method} {$path}",
            ]),
        };
    }

    /**
     * Ask this user's bridge what is left of its subscription, and answer when it replies.
     *
     * The only endpoint here that waits on the bridge. It has to: the figures are no use
     * cached, and the caller is a person who just opened a panel. So the response is held
     * open, the frame goes out, and whichever happens first finishes it — the bridge's reply
     * or the timeout.
     *
     * **The user is always the token's subject, never anything the caller sent**, exactly as
     * in apiRequest(). A relay token is scoped to one connection and that is the connection
     * it may ask about.
     *
     * A bridge too old to know the frame simply never answers, which is indistinguishable
     * from one that is wedged, and both are the same answer to the person waiting: this
     * machine cannot tell you. That is why there is no version negotiation here.
     */
    private function apiUsage(ConnectionInterface $tcpConnection, RequestInterface $request, object $decoded): void
    {
        $userId = (string) ($decoded->sub ?? '');

        if ($userId === '') {
            $this->httpResponse($tcpConnection, 400, [
                'error' => 'missing_subject',
                'message' => 'Token carries no subject.',
            ]);

            return;
        }

        if (! $this->connectionManager->hasConnection($userId)) {
            $this->httpResponse($tcpConnection, 404, [
                'error' => 'bridge_not_connected',
                'message' => 'No bridge is connected for this user.',
            ]);

            return;
        }

        $requestId = 'usage-'.bin2hex(random_bytes(8));
        $answered = false;

        $this->connectionManager->registerPendingUsage(
            $requestId,
            $userId,
            function (array $answer) use (&$answered, $tcpConnection): void {
                if ($answered) {
                    return;
                }

                $answered = true;
                $this->httpResponse($tcpConnection, 200, $answer);
            }
        );

        // Which CLI to report on. Optional, but a machine can have several installed and only
        // the caller knows which one is answering the conversation; without it the bridge
        // refuses to guess rather than label one subscription's figures as another's.
        parse_str((string) $request->getUri()->getQuery(), $query);
        $provider = isset($query['provider']) && is_string($query['provider']) ? $query['provider'] : null;

        $sent = $this->connectionManager->sendToUser($userId, [
            'type' => MessageTypes::USAGE_REQUEST,
            'id' => $requestId,
            ...($provider !== null && $provider !== '' ? ['provider' => $provider] : []),
        ]);

        if (! $sent) {
            $this->connectionManager->forgetPendingUsage($requestId);
            $this->httpResponse($tcpConnection, 500, [
                'error' => 'send_failed',
                'message' => 'Could not put the question to the bridge.',
            ]);

            return;
        }

        // Without this the response is held open forever by a bridge that will never reply,
        // and the asker's own request eventually dies with no explanation.
        $timeout = (float) config('ai-bridge.server.usage_timeout', 12);

        $this->loop->addTimer($timeout, function () use ($requestId, &$answered, $tcpConnection): void {
            $this->connectionManager->forgetPendingUsage($requestId);

            if ($answered) {
                return;
            }

            $answered = true;
            $this->httpResponse($tcpConnection, 504, [
                'error' => 'bridge_did_not_answer',
                'message' => 'The bridge did not answer in time. It may be an older version that does not know the question.',
            ]);
        });
    }

    /**
     * POST /api/request/input — Send a message into a turn that is still running.
     *
     * Body: `{request_id, message_id, content}`, `content` being a string or a list of
     * content blocks. Answers `{status: 'accepted'|'rejected', reason?}` once the bridge's
     * `turn_input_ack` arrives, and waits no longer than `turn_input_timeout` for it.
     *
     * Rejections the bridge gives are passed on (`turn_not_running`, `input_not_open`).
     * The ones decided here:
     *  - `turn_not_running` — this process has no running turn under that id, so there is
     *    nothing to send it to (the turn ended, or never started). The caller then starts a
     *    new turn with the message, which is what that reason means.
     *  - `no_answer` — the bridge did not answer in time, or disconnected first. It may or
     *    may not have taken the message, so this must never read as `turn_not_running`.
     *  - `duplicate` — the same message is already waiting on its answer; sending it again
     *    would write it to the CLI twice.
     *  - `send_failed` — the frame could not be written to the bridge's socket.
     *
     * **The requesting user must own the turn**, checked exactly as apiRequest() checks a
     * caller-supplied request_id: the user is the token's subject, never the body, and a
     * turn registered to anyone else is refused with 403. Otherwise any relay token could
     * write into any running conversation whose request id it had learned.
     */
    private function apiRequestInput(ConnectionInterface $tcpConnection, RequestInterface $request, object $decoded): void
    {
        $body = json_decode((string) $request->getBody(), true);
        $userId = (string) ($decoded->sub ?? '');

        if ($userId === '') {
            $this->httpResponse($tcpConnection, 400, [
                'error' => 'missing_subject',
                'message' => 'Token is missing the "sub" claim.',
            ]);

            return;
        }

        // Shape-checked before anything touches it, for the reason apiRequest() gives: a
        // TypeError here is raised inside a ReactPHP callback and exits the serve process.
        $requestId = is_array($body) ? ($body['request_id'] ?? null) : null;
        $messageId = is_array($body) ? ($body['message_id'] ?? null) : null;
        $content = is_array($body) ? ($body['content'] ?? null) : null;

        if (! is_string($requestId) || $requestId === ''
            || ! is_string($messageId) || $messageId === ''
            || ! ((is_string($content) && $content !== '') || (is_array($content) && $content !== [] && array_is_list($content)))) {
            $this->httpResponse($tcpConnection, 400, [
                'error' => 'invalid_request',
                'message' => 'Body must be JSON with string "request_id" and "message_id", and "content" as a non-empty string or list of content blocks.',
            ]);

            return;
        }

        // Asked with getPendingRequest() rather than by owner: a request registered with a
        // falsy owner reads as absent through getPendingRequestUserId(), and must not be
        // mistaken for "not running" — it is someone's, just not provably this caller's.
        if ($this->connectionManager->getPendingRequest($requestId) === null) {
            $this->httpResponse($tcpConnection, 200, [
                'status' => 'rejected',
                'reason' => 'turn_not_running',
            ]);

            return;
        }

        if ($this->connectionManager->getPendingRequestUserId($requestId) !== $userId) {
            Log::warning('AI Bridge: turn input for a request owned by a different user — refused', [
                'request_id' => $requestId,
                'caller_user_id' => $userId,
            ]);

            // SEC: not the owner's id, and not whether it is running — only a refusal.
            $this->httpResponse($tcpConnection, 403, [
                'status' => 'rejected',
                'reason' => 'not_owner',
            ]);

            return;
        }

        if ($this->connectionManager->hasPendingTurnInput($requestId, $messageId)) {
            $this->httpResponse($tcpConnection, 200, [
                'status' => 'rejected',
                'reason' => 'duplicate',
            ]);

            return;
        }

        $answered = false;

        // Registered BEFORE sending, as apiRequest() registers its turn: a bridge on the same
        // host can acknowledge before sendToUser() has even returned.
        $this->connectionManager->registerPendingTurnInput(
            $requestId,
            $messageId,
            $userId,
            function (array $answer) use (&$answered, $tcpConnection): void {
                if ($answered) {
                    return;
                }

                $answered = true;
                $this->httpResponse($tcpConnection, 200, $answer);
            }
        );

        $sent = $this->connectionManager->sendToUser($userId, [
            'type' => MessageTypes::TURN_INPUT,
            'request_id' => $requestId,
            'message_id' => $messageId,
            'content' => $content,
        ]);

        if (! $sent) {
            $this->connectionManager->forgetPendingTurnInput($requestId, $messageId);

            if (! $answered) {
                $answered = true;
                $this->httpResponse($tcpConnection, 200, [
                    'status' => 'rejected',
                    'reason' => 'send_failed',
                ]);
            }

            return;
        }

        if ($answered) {
            return;
        }

        // A bridge too old to know turn_input never answers. It also never confirms
        // `input_open`, so a well-behaved caller does not get here with one — but a caller
        // that does must not be held open for ever.
        $timeout = (float) config('ai-bridge.server.turn_input_timeout', 5);

        $this->loop->addTimer($timeout, function () use ($requestId, $messageId, &$answered, $tcpConnection): void {
            $this->connectionManager->forgetPendingTurnInput($requestId, $messageId);

            if ($answered) {
                return;
            }

            $answered = true;
            $this->httpResponse($tcpConnection, 200, [
                'status' => 'rejected',
                'reason' => 'no_answer',
            ]);
        });
    }

    /**
     * GET /api/status — Return connection status for the authenticated user only.
     *
     * SEC: Only shows the requesting user's own connection data, not all users.
     */
    private function apiStatus(ConnectionInterface $tcpConnection, object $decoded): void
    {
        $userId = (string) ($decoded->sub ?? '');

        if (empty($userId)) {
            $this->httpResponse($tcpConnection, 400, [
                'error' => 'missing_subject',
                'message' => 'Token is missing the "sub" claim.',
            ]);

            return;
        }

        $connected = $this->connectionManager->hasConnection($userId);
        $response = [
            'user_id' => $userId,
            'connected' => $connected,
        ];

        if ($connected) {
            $data = $this->connectionManager->getConnection($userId);
            $providers = $this->connectionManager->getProviders($userId);

            $response['connection_id'] = $data['connection_id'] ?? null;
            $response['connected_at'] = $data['connected_at'] ?? null;
            $response['providers'] = $providers;
            // The directories this bridge will work in. The app needs them to
            // render a workspace picker, and only this process knows them.
            $response['workspaces'] = $this->connectionManager->getWorkspaces($userId);
            // What the bridge is actually running as, which only this process
            // knows and which a PHP-FPM worker otherwise cannot see.
            $response['posture'] = $this->connectionManager->getPosture($userId);
        }

        $this->httpResponse($tcpConnection, 200, $response);
    }

    /**
     * POST /api/disconnect — Forcibly drop the authenticated user's bridge.
     *
     * Called by the web app when a connection is deleted or its token is
     * regenerated. Closes the live WebSocket with code 4001 so the CLI exits
     * instead of reconnecting. The user is the JWT sub claim — a relay token
     * can only ever disconnect its own connection.
     */
    private function apiDisconnect(ConnectionInterface $tcpConnection, object $decoded): void
    {
        $userId = (string) ($decoded->sub ?? '');

        if (empty($userId)) {
            $this->httpResponse($tcpConnection, 400, [
                'error' => 'missing_subject',
                'message' => 'Token is missing the "sub" claim.',
            ]);

            return;
        }

        $disconnected = $this->connectionManager->disconnectUser($userId, 'connection_revoked');

        $this->httpResponse($tcpConnection, 200, [
            'user_id' => $userId,
            'disconnected' => $disconnected,
        ]);
    }

    /**
     * POST /api/request — Send an ai_request to a user's connected bridge.
     *
     * The payload is shaped by AiRequestPayload, the same builder
     * BridgeStream::buildRequestBody() uses, so this relay path and the direct
     * WebSocket path cannot drift apart. They used to be two hand-maintained
     * copies, and a field added to one and missed here would work under Octane
     * and silently do nothing under PHP-FPM.
     *
     * Expected body:
     * {
     *   "provider": "claude",
     *   "message": "Hello!",
     *   "conversation_id": "conv-001",
     *   "system_prompt": "You are helpful.",
     *   "options": { "max_tokens": 100 }
     * }
     *
     * The user_id is derived from the JWT sub claim — never from the request body.
     */
    private function apiRequest(ConnectionInterface $tcpConnection, RequestInterface $request, object $decoded): void
    {
        $rawBody = (string) $request->getBody();
        $body = json_decode($rawBody, true);

        if (! is_array($body)) {
            $this->httpResponse($tcpConnection, 400, [
                'error' => 'invalid_body',
                'message' => 'Request body must be valid JSON.',
            ]);

            return;
        }

        // SEC: user_id is always derived from the JWT sub claim, not the request body.
        // This prevents users from impersonating other users' bridge connections.
        $userId = (string) ($decoded->sub ?? '');

        // Shape-check the fields this method handles ITSELF, before anything
        // touches them. `strict_types=1` is on, so a numeric `request_id`
        // passed to a `string` parameter is a TypeError, and `(string) []` is
        // an Error — raised in a ReactPHP data callback that has no try/catch,
        // which exits the process and drops every connected bridge rather than
        // failing this one request. The payload builder guards its own fields;
        // these three are handled here and were still unguarded.
        foreach (['request_id', 'provider', 'message', 'conversation_id', 'cli_session_id'] as $field) {
            if (isset($body[$field]) && ! is_string($body[$field]) && ! is_numeric($body[$field])) {
                $this->httpResponse($tcpConnection, 400, [
                    'error' => 'invalid_request',
                    'message' => "Field \"{$field}\" must be a string.",
                ]);

                return;
            }
        }

        $provider = isset($body['provider']) ? (string) $body['provider'] : '';
        $message = isset($body['message']) ? (string) $body['message'] : '';
        $conversationId = isset($body['conversation_id']) ? (string) $body['conversation_id'] : '';

        // Only 'message' is required. 'provider' is optional routing metadata —
        // the bridge client can fall back to its configured default when omitted.
        if (empty($message)) {
            $this->httpResponse($tcpConnection, 400, [
                'error' => 'missing_fields',
                'message' => 'Field "message" is required.',
            ]);

            return;
        }

        if (! $this->connectionManager->hasConnection($userId)) {
            // SEC: Don't leak connected user IDs in error responses
            $this->httpResponse($tcpConnection, 404, [
                'error' => 'bridge_not_connected',
                'message' => 'No active bridge connection for this user.',
            ]);

            return;
        }

        // Build ai_request payload — use the request_id from the relay body if provided,
        // to preserve the original request_id for event routing back to the caller.
        // Validate that a caller-supplied request_id is not already registered as
        // a pending request owned by a different user, to prevent stream event
        // hijacking. If it is, generate a fresh one.
        $callerRequestId = ! empty($body['request_id']) ? (string) $body['request_id'] : null;
        if ($callerRequestId !== null) {
            $pendingOwner = $this->connectionManager->getPendingRequestUserId($callerRequestId);
            if ($pendingOwner !== null && $pendingOwner !== $userId) {
                // The supplied request_id is owned by another user — generate a fresh one
                Log::warning('AI Bridge: caller supplied a request_id owned by a different user, generating new one', [
                    'supplied_request_id' => $callerRequestId,
                    'caller_user_id' => $userId,
                    'owner_user_id' => $pendingOwner,
                ]);
                $callerRequestId = null;
            }
        }
        $requestId = $callerRequestId ?? 'req-' . bin2hex(random_bytes(8));

        try {
            $payload = AiRequestPayload::fromRelayBody($body, $requestId);
        } catch (\Throwable $e) {
            // \Throwable, not \InvalidArgumentException. This runs inside the
            // ReactPHP connection callback, which has no try/catch of its own:
            // anything that escapes here does not fail one request, it exits
            // the serve process and drops every connected bridge. A malformed
            // relay body must never be able to do that.
            // BridgeLog, not Log: this is the catch that exists to stop an
            // exception escaping the event loop, and a Monolog write failure
            // (full disk, permissions) inside it would do exactly that.
            // BridgeLog swallows its own failures. Same reasoning as the
            // comment in BridgeStream::relayViaHttpApi().
            BridgeLog::warning('rejected a malformed relay request', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);

            // Generic to the caller, detailed in the log. The caller here holds
            // an internal relay token, so this is not an exposure so much as a
            // habit worth keeping.
            $this->httpResponse($tcpConnection, 400, [
                'error' => 'invalid_request',
                'message' => 'The request body could not be understood.',
            ]);

            return;
        }

        // Register the relayed request as pending so incoming tool calls and
        // stream events from the bridge can be verified and buffered for the
        // browser's SSE tail. Register BEFORE sending so a fast bridge reply
        // cannot race ahead of the registration.
        $this->messageHandler->registerRelayedRequest($requestId, $userId, (string) $conversationId);

        $sent = $this->connectionManager->sendToUser($userId, $payload);

        if (! $sent) {
            $this->httpResponse($tcpConnection, 500, [
                'error' => 'send_failed',
                'message' => 'Failed to send ai_request to bridge.',
            ]);

            return;
        }

        $this->httpResponse($tcpConnection, 200, [
            'ok' => true,
            'request_id' => $requestId,
            'user_id' => $userId,
            'provider' => $provider,
        ]);
    }

    /**
     * Send an HTTP JSON response on the TCP connection and close it.
     *
     * @param  array<string, mixed>  $data
     */
    private function httpResponse(ConnectionInterface $tcpConnection, int $statusCode, array $data): void
    {
        // 413 is included so the buffer-overflow guard in handleTcpConnection()
        // can use httpResponse() consistently.
        $statusTexts = [200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 413 => 'Payload Too Large', 500 => 'Internal Server Error', 504 => 'Gateway Timeout'];
        $statusText = $statusTexts[$statusCode] ?? 'Unknown';

        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        $length = strlen($json);

        $response = "HTTP/1.1 {$statusCode} {$statusText}\r\n" .
            "Content-Type: application/json\r\n" .
            "Content-Length: {$length}\r\n" .
            "Connection: close\r\n" .
            "\r\n" .
            $json;

        $tcpConnection->write($response);
        $tcpConnection->end();
    }
}
