<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tests\TestCase;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/*
|--------------------------------------------------------------------------
| MessageHandler Unit Tests
|--------------------------------------------------------------------------
|
| Tests for ownership checks (SEC-003, SEC-004), protocol message routing,
| and stream event dispatch. Also covers CONS-006 (tool_call_id key).
|
*/


function makeMessageHandler(?BridgeConnectionManager $manager = null, ?ToolRegistry $registry = null): MessageHandler
{
    return new MessageHandler(
        connectionManager: $manager ?? new BridgeConnectionManager(),
        tokenManager: app(TokenManager::class),
        toolRegistry: $registry ?? new ToolRegistry(),
    );
}

function makeHandler(BridgeConnectionManager $manager): StreamHandler
{
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $handler = new StreamHandler($provider);
    $handler->setMode(ProviderMode::Byok);
    $handler->setConversationId('test-conv');

    return $handler;
}

beforeEach(function () {
    Event::fake();
    $this->manager = new BridgeConnectionManager();
    $this->messageHandler = makeMessageHandler($this->manager);
});

// --- SEC-003: top-level tool_call ownership ---

test('tool_call message from correct user is processed (SEC-003)', function () {
    $handler = makeHandler($this->manager);

    $toolCallFired = false;
    $handler->onToolCall(function () use (&$toolCallFired) {
        $toolCallFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    // Register a tool so we get a resolve response back
    $registry = new ToolRegistry();
    $registry->register('echo', 'Echo test', ['type' => 'object'], fn ($p) => ['echoed' => $p]);
    $mh = makeMessageHandler($this->manager, $registry);

    $rawMsg = json_encode([
        'type' => MessageTypes::TOOL_CALL,
        'request_id' => 'req-1',
        'tool_name' => 'echo',
        'parameters' => ['val' => 'hi'],
        'call_id' => 'call-123',
    ]);

    $response = $mh->handleMessage('conn-1', null, $rawMsg);

    // Should return a tool_resolve (the tool ran successfully)
    expect($response)->toBeArray();
    expect($response['type'])->toBe(MessageTypes::TOOL_RESOLVE);
});

test('tool_call message from wrong user is discarded (SEC-003)', function () {
    $handler = makeHandler($this->manager);

    $toolCallFired = false;
    $handler->onToolCall(function () use (&$toolCallFired) {
        $toolCallFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2'); // different user, different connection
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::TOOL_CALL,
        'request_id' => 'req-1',
        'tool_name' => 'any_tool',
        'parameters' => [],
        'call_id' => 'call-999',
    ]);

    // conn-2 belongs to user-2, but the request belongs to user-1
    $response = $this->messageHandler->handleMessage('conn-2', null, $rawMsg);

    expect($response)->toBeNull(); // Discarded — ownership mismatch
    expect($toolCallFired)->toBeFalse(); // Callback must not fire
});

test('tool_call from unregistered connection is discarded (SEC-003 fail-closed)', function () {
    $handler = makeHandler($this->manager);
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::TOOL_CALL,
        'request_id' => 'req-1',
        'tool_name' => 'any_tool',
        'parameters' => [],
        'call_id' => 'call-999',
    ]);

    // 'conn-unknown' is not registered in the manager — senderUserId will be null
    $response = $this->messageHandler->handleMessage('conn-unknown', null, $rawMsg);

    expect($response)->toBeNull(); // Discarded — fail-closed on null senderUserId
});

// --- SEC-004: stream envelope ownership ---

test('stream block_delta from correct user is dispatched (SEC-004)', function () {
    $handler = makeHandler($this->manager);

    $receivedContent = null;
    $handler->onBlockDelta(function (StreamEvent $event) use (&$receivedContent) {
        $receivedContent = $event->data['content'] ?? null;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::BLOCK_DELTA,
        'data' => ['block_type' => 'text', 'block_index' => 0, 'content' => 'hello'],
    ]);

    $response = $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($response)->toBeNull();
    expect($receivedContent)->toBe('hello');
});

test('a helper task event from the owning bridge reaches onTask whole', function () {
    $handler = makeHandler($this->manager);

    $seen = [];
    $handler->onTask(function (array $task) use (&$seen) {
        $seen[] = $task;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $data = [
        'phase' => 'progress', 'task_id' => 'af2e05936428f6e8e', 'tool_use_id' => 'toolu_agent',
        'subagent_type' => 'general-purpose', 'description' => 'Running php artisan migrate --pretend',
        'last_tool_name' => 'Bash', 'usage' => ['total_tokens' => 23921, 'tool_uses' => 1, 'duration_ms' => 2976],
        'some_future_field' => 'kept',
    ];
    $response = $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::TASK,
        'data' => $data,
    ]));

    expect($response)->toBeNull()
        ->and($seen)->toBe([$data]);
});

test('a helper task event from another user is discarded (SEC-004)', function () {
    $handler = makeHandler($this->manager);

    $fired = false;
    $handler->onTask(function () use (&$fired) {
        $fired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $this->messageHandler->handleMessage('conn-2', null, json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::TASK,
        'data' => ['phase' => 'heartbeat', 'task_id' => 't1', 'tool_use_id' => 'toolu_agent', 'elapsed_seconds' => 30],
    ]));

    expect($fired)->toBeFalse();
});

test('a helper block and its result arrive over the wire with their parent', function () {
    $handler = makeHandler($this->manager);

    $blockParent = null;
    $resultParent = 'unset';
    $handler->onBlockStart(function (StreamEvent $event) use (&$blockParent) {
        $blockParent = $event->data['parent_tool_use_id'] ?? null;
    });
    $handler->onToolResult(function (string $id, mixed $result, ?bool $isError, ?string $parent) use (&$resultParent) {
        $resultParent = $parent;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    foreach ([
        [MessageTypes::BLOCK_START, ['block_index' => 2, 'block_type' => 'tool_call', 'tool_name' => 'Bash', 'tool_call_id' => 'toolu_child', 'parent_tool_use_id' => 'toolu_agent']],
        [MessageTypes::BLOCK_STOP, ['block_index' => 2]],
        [MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_child', 'result' => 'helper-done', 'is_error' => false, 'parent_tool_use_id' => 'toolu_agent']],
    ] as [$event, $data]) {
        $this->messageHandler->handleMessage('conn-1', null, json_encode([
            'type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => $event, 'data' => $data,
        ]));
    }

    expect($blockParent)->toBe('toolu_agent')
        ->and($resultParent)->toBe('toolu_agent');
});

test('stream block_delta from wrong user is discarded (SEC-004)', function () {
    $handler = makeHandler($this->manager);

    $receivedContent = null;
    $handler->onBlockDelta(function (StreamEvent $event) use (&$receivedContent) {
        $receivedContent = $event->data['content'] ?? null;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::BLOCK_DELTA,
        'data' => ['block_type' => 'text', 'block_index' => 0, 'content' => 'injected'],
    ]);

    // conn-2 belongs to user-2, but req-1 belongs to user-1
    $response = $this->messageHandler->handleMessage('conn-2', null, $rawMsg);

    expect($response)->toBeNull();
    expect($receivedContent)->toBeNull(); // Must not dispatch injected event
});

test('stream event from unregistered connection is discarded (SEC-004 fail-closed)', function () {
    $handler = makeHandler($this->manager);
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $receivedContent = null;
    $handler->onBlockDelta(function (StreamEvent $event) use (&$receivedContent) {
        $receivedContent = $event->data['content'] ?? null;
    });

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::BLOCK_DELTA,
        'data' => ['block_type' => 'text', 'block_index' => 0, 'content' => 'injected'],
    ]);

    // conn-unknown has no registered userId — fail-closed means null userId => reject
    $response = $this->messageHandler->handleMessage('conn-unknown', null, $rawMsg);

    expect($response)->toBeNull();
    expect($receivedContent)->toBeNull();
});

// --- done/error ownership checks (BL-007) ---

test('stream done from correct user is dispatched (BL-007)', function () {
    $handler = makeHandler($this->manager);

    $doneFired = false;
    $handler->onDone(function () use (&$doneFired) {
        $doneFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::DONE,
        'data' => [],
    ]);

    $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($doneFired)->toBeTrue();
    expect($this->manager->getPendingRequest('req-1'))->toBeNull();
});

test('stream done from wrong user is discarded (BL-007)', function () {
    $handler = makeHandler($this->manager);

    $doneFired = false;
    $handler->onDone(function () use (&$doneFired) {
        $doneFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::DONE,
        'data' => [],
    ]);

    $this->messageHandler->handleMessage('conn-2', null, $rawMsg);

    expect($doneFired)->toBeFalse();
    // Request should still be pending since done was discarded
    expect($this->manager->getPendingRequest('req-1'))->not->toBeNull();
});

test('stream error from wrong user is discarded (BL-007)', function () {
    $handler = makeHandler($this->manager);

    $errorFired = false;
    $handler->onError(function () use (&$errorFired) {
        $errorFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::ERROR,
        'data' => ['code' => 'fake_error', 'message' => 'spoofed'],
    ]);

    $this->messageHandler->handleMessage('conn-2', null, $rawMsg);

    expect($errorFired)->toBeFalse();
    expect($this->manager->getPendingRequest('req-1'))->not->toBeNull();
});

// --- CONS-006: StreamEvent::toolCall() uses 'tool_call_id' key ---

test("StreamEvent::toolCall() stores 'tool_call_id' not 'call_id' (CONS-006)", function () {
    $event = StreamEvent::toolCall('req-1', 'search', ['q' => 'cats'], 'call-xyz');

    expect($event->data)->toHaveKey('tool_call_id');
    expect($event->data['tool_call_id'])->toBe('call-xyz');
    expect($event->data)->not->toHaveKey('call_id');
});

test('StreamHandler::dispatchEvent() reads tool_call_id from event data (CONS-006)', function () {
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $streamHandler = new StreamHandler($provider);
    $streamHandler->setMode(ProviderMode::Byok);
    $streamHandler->setConversationId('conv-1');

    $receivedCallId = null;
    $streamHandler->onToolCall(function (string $name, array $params, string $callId) use (&$receivedCallId) {
        $receivedCallId = $callId;
    });

    // Create an event using the canonical tool_call_id key
    $event = StreamEvent::toolCall('req-1', 'search', ['q' => 'cats'], 'call-xyz');
    $streamHandler->dispatchEvent($event);

    expect($receivedCallId)->toBe('call-xyz');
});

test('StreamHandler::dispatchEvent() falls back to call_id for legacy events (CONS-006)', function () {
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $streamHandler = new StreamHandler($provider);
    $streamHandler->setMode(ProviderMode::Byok);
    $streamHandler->setConversationId('conv-1');

    $receivedCallId = null;
    $streamHandler->onToolCall(function (string $name, array $params, string $callId) use (&$receivedCallId) {
        $receivedCallId = $callId;
    });

    // Simulate a legacy event that uses the old 'call_id' key
    $event = new StreamEvent('req-1', MessageTypes::TOOL_CALL, [
        'tool_name' => 'search',
        'parameters' => [],
        'call_id' => 'legacy-call-id',
    ]);
    $streamHandler->dispatchEvent($event);

    expect($receivedCallId)->toBe('legacy-call-id');
});

// --- BL-001: stream-envelope tool_call ownership bypass ---

test('stream-envelope tool_call from correct user is processed (BL-001)', function () {
    $registry = new ToolRegistry();
    $registry->register('ping', 'Ping tool', ['type' => 'object'], fn ($p) => ['pong' => true]);
    $mh = makeMessageHandler($this->manager, $registry);

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', makeHandler($this->manager), 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::TOOL_CALL,
        'data' => [
            'tool_name' => 'ping',
            'parameters' => [],
            'tool_call_id' => 'call-abc',
        ],
    ]);

    $response = $mh->handleMessage('conn-1', null, $rawMsg);

    // The tool ran and returned a tool_resolve
    expect($response)->toBeArray();
    expect($response['type'])->toBe(MessageTypes::TOOL_RESOLVE);
    expect($response['result'])->toBe(['pong' => true]);
});

test('stream-envelope tool_call from wrong user is discarded (BL-001)', function () {
    $registry = new ToolRegistry();
    $toolExecuted = false;
    $registry->register('ping', 'Ping tool', ['type' => 'object'], function ($p) use (&$toolExecuted) {
        $toolExecuted = true;
        return ['pong' => true];
    });
    $mh = makeMessageHandler($this->manager, $registry);

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');
    $this->manager->registerPendingRequest('req-1', makeHandler($this->manager), 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::TOOL_CALL,
        'data' => [
            'tool_name' => 'ping',
            'parameters' => [],
            'tool_call_id' => 'call-evil',
        ],
    ]);

    // conn-2 belongs to user-2, but the request belongs to user-1
    $response = $mh->handleMessage('conn-2', null, $rawMsg);

    expect($response)->toBeNull(); // Discarded — ownership mismatch
    expect($toolExecuted)->toBeFalse(); // Tool must NOT execute
});

test('stream-envelope tool_call from unregistered connection is discarded (BL-001 fail-closed)', function () {
    $registry = new ToolRegistry();
    $toolExecuted = false;
    $registry->register('ping', 'Ping tool', ['type' => 'object'], function ($p) use (&$toolExecuted) {
        $toolExecuted = true;
        return [];
    });
    $mh = makeMessageHandler($this->manager, $registry);

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', makeHandler($this->manager), 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::TOOL_CALL,
        'data' => [
            'tool_name' => 'ping',
            'parameters' => [],
            'tool_call_id' => 'call-ghost',
        ],
    ]);

    // 'conn-ghost' is not registered — verifySenderOwnsRequest must reject it
    $response = $mh->handleMessage('conn-ghost', null, $rawMsg);

    expect($response)->toBeNull();
    expect($toolExecuted)->toBeFalse();
});

// --- SEC-001: CANCELLED handler ownership check ---

test('cancelled message from correct user dispatches cancellation (SEC-001)', function () {
    $handler = makeHandler($this->manager);

    $cancelledFired = false;
    $handler->onCancelled(function () use (&$cancelledFired) {
        $cancelledFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::CANCELLED,
        'request_id' => 'req-1',
    ]);

    $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($cancelledFired)->toBeTrue();
    expect($this->manager->getPendingRequest('req-1'))->toBeNull(); // cleaned up
});

test('cancelled message from wrong user is discarded (SEC-001)', function () {
    $handler = makeHandler($this->manager);

    $cancelledFired = false;
    $handler->onCancelled(function () use (&$cancelledFired) {
        $cancelledFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::CANCELLED,
        'request_id' => 'req-1',
    ]);

    // conn-2 belongs to user-2, but request belongs to user-1
    $this->messageHandler->handleMessage('conn-2', null, $rawMsg);

    expect($cancelledFired)->toBeFalse();
    expect($this->manager->getPendingRequest('req-1'))->not->toBeNull(); // NOT cleaned up
});

test('cancelled message from unregistered connection is discarded (SEC-001 fail-closed)', function () {
    $handler = makeHandler($this->manager);

    $cancelledFired = false;
    $handler->onCancelled(function () use (&$cancelledFired) {
        $cancelledFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::CANCELLED,
        'request_id' => 'req-1',
    ]);

    // 'conn-unknown' is not in the manager — fail-closed means null userId => reject
    $this->messageHandler->handleMessage('conn-unknown', null, $rawMsg);

    expect($cancelledFired)->toBeFalse();
    expect($this->manager->getPendingRequest('req-1'))->not->toBeNull();
});

test('cancelled for a turn already cleaned up is not treated as an attack', function () {
    // The ORDINARY ending, now that the bridge answers a cancel at all: the
    // abort path terminates the turn locally and clears the pending request the
    // moment it sees the flag, and the bridge's reply waits for the CLI to
    // actually stop — so it always lands after. A warning here would fire on
    // every cancelled turn, which is how a real warning stops being read.
    Log::spy();
    $this->manager->addConnection('user-1', 'conn-1');

    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::CANCELLED,
        'request_id' => 'req-long-gone',
    ]));

    Log::shouldNotHaveReceived('warning');
});

test('a stop is noticed on the heartbeat, not only when the turn says something', function () {
    // The abort flag used to be polled in exactly one place: as each stream
    // event arrived. So it was read constantly while the model was writing and
    // never while it was not — and a turn three minutes into a build, which is
    // when somebody actually presses stop, ignored the button completely.
    $store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $store);

    $sent = [];
    $this->manager->setSendCallback(function (mixed $conn, array $payload) use (&$sent) {
        $sent[] = $payload;

        return true;
    });
    $this->manager->addConnection('user-1', 'conn-1');

    $handler = makeHandler($this->manager);
    $cancelled = false;
    $handler->onCancelled(function () use (&$cancelled) {
        $cancelled = true;
    });
    $this->manager->registerPendingRequest('req-quiet', $handler, 'user-1');
    $store->setAbort('req-quiet');

    // The heartbeat: the only thing a silent turn still produces.
    $response = $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::PING,
        'timestamp' => 123,
    ]));

    expect($response['type'])->toBe(MessageTypes::PONG)
        ->and($cancelled)->toBeTrue()
        ->and(collect($sent)->firstWhere('type', MessageTypes::CANCEL))->not->toBeNull();
});

test('a heartbeat does not disturb a turn nobody stopped', function () {
    $store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $store);

    $sent = [];
    $this->manager->setSendCallback(function (mixed $conn, array $payload) use (&$sent) {
        $sent[] = $payload;

        return true;
    });
    $this->manager->addConnection('user-1', 'conn-1');

    $handler = makeHandler($this->manager);
    $cancelled = false;
    $handler->onCancelled(function () use (&$cancelled) {
        $cancelled = true;
    });
    $this->manager->registerPendingRequest('req-running', $handler, 'user-1');

    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::PING,
        'timestamp' => 123,
    ]));

    expect($cancelled)->toBeFalse()
        ->and($sent)->toBeEmpty()
        ->and($this->manager->getPendingRequest('req-running'))->not->toBeNull();
});

test('a heartbeat only ever stops the sender own turns (SEC-001)', function () {
    // The property the per-user filter exists for, and nothing else asserts it:
    // drop the filter and one user's heartbeat becomes a kill switch for every
    // in-flight turn on the server. Before this poll existed, ownership was
    // checked on the stream event that carried us here.
    $store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $store);

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');

    $mine = makeHandler($this->manager);
    $theirs = makeHandler($this->manager);
    $minesCancelled = false;
    $theirsCancelled = false;
    $mine->onCancelled(function () use (&$minesCancelled) {
        $minesCancelled = true;
    });
    $theirs->onCancelled(function () use (&$theirsCancelled) {
        $theirsCancelled = true;
    });
    $this->manager->registerPendingRequest('req-mine', $mine, 'user-1');
    $this->manager->registerPendingRequest('req-theirs', $theirs, 'user-2');

    // Both turns are stopped, but only user-1 is sending this heartbeat.
    $store->setAbort('req-mine');
    $store->setAbort('req-theirs');

    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::PING,
        'timestamp' => 123,
    ]));

    expect($minesCancelled)->toBeTrue()
        ->and($theirsCancelled)->toBeFalse()
        ->and($this->manager->getPendingRequest('req-theirs'))->not->toBeNull();
});

test('a heartbeat from a connection with no user id touches nothing', function () {
    // '' is not a user. A request registered without an owner is exactly what
    // the ownership check fails closed on, so this path must not match it.
    $store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $store);

    $this->manager->addConnection('', 'conn-anon');

    $handler = makeHandler($this->manager);
    $cancelled = false;
    $handler->onCancelled(function () use (&$cancelled) {
        $cancelled = true;
    });
    $this->manager->registerPendingRequest('req-unowned', $handler);
    $store->setAbort('req-unowned');

    $this->messageHandler->handleMessage('conn-anon', null, json_encode([
        'type' => MessageTypes::PING,
        'timestamp' => 123,
    ]));

    expect($cancelled)->toBeFalse();
});

// --- Protocol version mismatch (ARCH-005) ---

test('hello with incompatible major protocol version is rejected (ARCH-005)', function () {
    $rawMsg = json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '1.0',
        'providers' => [],
        'token' => 'ignored',
    ]);

    $response = $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($response)->toBeArray();
    expect($response['type'])->toBe(MessageTypes::CONNECTION_ERROR);
    expect($response['error'])->toBe('protocol_version_mismatch');
});

test('hello with compatible minor version difference is accepted (ARCH-005)', function () {
    // Generate a valid token for the hello message
    $token = app(TokenManager::class)->generate('user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '0.5',  // Major=0 matches, minor difference is fine
        'providers' => [],
        'token' => $token,
    ]);

    $response = $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($response)->toBeArray();
    expect($response['type'])->toBe(MessageTypes::WELCOME);
});

// --- EFF-006: MessageTypes::all() caching ---

test('MessageTypes::all() returns same array reference on second call (EFF-006)', function () {
    // First call populates the cache
    $first = \Tetrix\AiBridge\Protocol\MessageTypes::all();
    // Second call must hit the cache and return identical result
    $second = \Tetrix\AiBridge\Protocol\MessageTypes::all();

    expect($first)->toBe($second); // Same array, not just equal
});

test('MessageTypes::all() contains all expected message type constants (EFF-006)', function () {
    $all = \Tetrix\AiBridge\Protocol\MessageTypes::all();

    expect($all)->toContain(MessageTypes::HELLO);
    expect($all)->toContain(MessageTypes::WELCOME);
    expect($all)->toContain(MessageTypes::PING);
    expect($all)->toContain(MessageTypes::PONG);
    expect($all)->toContain(MessageTypes::STREAM);
    expect($all)->toContain(MessageTypes::DONE);
    expect($all)->toContain(MessageTypes::TOOL_CALL);
    expect($all)->toContain(MessageTypes::CANCELLED);
    expect($all)->toContain(MessageTypes::TOKEN_REFRESH);
    expect($all)->toContain(MessageTypes::PROVIDERS_UPDATE);
    expect($all)->toContain(MessageTypes::ATTACHMENT);
    expect($all)->toContain(MessageTypes::POSTURE);
    expect($all)->toContain(MessageTypes::RATE_LIMIT);
    expect($all)->toContain(MessageTypes::USAGE_REQUEST);
    expect($all)->toContain(MessageTypes::USAGE_RESULT);
    expect($all)->toContain(MessageTypes::TASK);
    expect($all)->toContain(MessageTypes::TURN_INPUT);
    expect($all)->toContain(MessageTypes::TURN_INPUT_ACK);
    expect($all)->toContain(MessageTypes::USER_INPUT);
    expect($all)->toContain(MessageTypes::MAIN_STATE);
    expect($all)->toContain(MessageTypes::UPLOAD_OFFER);
    expect($all)->toContain(MessageTypes::FILE_READ_RESULT);
    expect($all)->toContain(MessageTypes::INPUT_CLOSED);
    expect($all)->toContain(MessageTypes::MCP_REQUEST);
    expect($all)->toContain(MessageTypes::UPSTREAM_AUTH_RESULT);
    expect($all)->toContain(MessageTypes::STREAM_ACK);
    expect($all)->toHaveCount(49);
});

test('turn_input is the server\'s to send and turn_input_ack the bridge\'s', function () {
    expect(MessageTypes::serverOrigin())->toContain(MessageTypes::TURN_INPUT)
        ->not->toContain(MessageTypes::TURN_INPUT_ACK)
        ->and(MessageTypes::bridgeOrigin())->toContain(MessageTypes::TURN_INPUT_ACK)
        ->not->toContain(MessageTypes::TURN_INPUT)
        // Stream events travel inside the `stream` envelope, like task.
        ->not->toContain(MessageTypes::USER_INPUT)
        ->not->toContain(MessageTypes::MAIN_STATE);
});

test('MessageTypes::isValid() accepts known types and rejects unknown (EFF-006)', function () {
    expect(MessageTypes::isValid(MessageTypes::HELLO))->toBeTrue();
    expect(MessageTypes::isValid(MessageTypes::STREAM))->toBeTrue();
    expect(MessageTypes::isValid('not_a_real_type'))->toBeFalse();
    expect(MessageTypes::isValid(''))->toBeFalse();
});

// --- Relay path: registerRelayedRequest (PHP-FPM relay fix) ---

test('registerRelayedRequest registers a pending request with the owner user', function () {
    $this->messageHandler->registerRelayedRequest('req-relay', 'user-1', 'conv-1');

    expect($this->manager->getPendingRequestUserId('req-relay'))->toBe('user-1');
    expect($this->manager->getPendingRequest('req-relay'))->toBeInstanceOf(StreamHandler::class);
});

test('registerRelayedRequest binds the supplied request_id to the StreamHandler', function () {
    $this->messageHandler->registerRelayedRequest('req-relay-id', 'user-1', 'conv-1');

    $handler = $this->manager->getPendingRequest('req-relay-id');
    expect($handler)->not->toBeNull();
    expect($handler->requestId)->toBe('req-relay-id');
});

test('a tool_call for a relayed request executes a registered tool and yields tool_resolve', function () {
    $registry = new ToolRegistry();
    $registry->register('echo', 'Echo test', ['type' => 'object'], fn ($p) => ['echoed' => $p]);
    $mh = makeMessageHandler($this->manager, $registry);

    // Simulate the serve process accepting a relayed (PHP-FPM) request.
    $this->manager->addConnection('user-1', 'conn-1');
    $mh->registerRelayedRequest('req-relay-tool', 'user-1', 'conv-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::TOOL_CALL,
        'request_id' => 'req-relay-tool',
        'tool_name' => 'echo',
        'parameters' => ['val' => 'hi'],
        'call_id' => 'call-relay-1',
    ]);

    $response = $mh->handleMessage('conn-1', null, $rawMsg);

    expect($response)->toBeArray();
    expect($response['type'])->toBe(MessageTypes::TOOL_RESOLVE);
    expect($response['result'])->toBe(['echoed' => ['val' => 'hi']]);
});

// --- Handler-registered tools execute through the real WS path (parity with closures) ---

test('a tool_call executes a ToolHandler-registered tool and yields tool_resolve', function () {
    // A tool registered via registerHandler() (ToolHandler instance), NOT a closure.
    $registry = new ToolRegistry();
    $registry->registerHandler(new class extends \Tetrix\AiBridge\Tools\AbstractTool {
        public function name(): string
        {
            return 'handler_echo';
        }

        public function description(): string
        {
            return 'Echo via a ToolHandler instance.';
        }

        protected function defineParameters(): array
        {
            return [
                new \Tetrix\AiBridge\Tools\ToolParameter('val', 'string', 'A value to echo back.', required: false),
            ];
        }

        public function handle(array $params): mixed
        {
            return ['echoed' => $params, 'via' => 'handler'];
        }
    });

    $mh = makeMessageHandler($this->manager, $registry);

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', makeHandler($this->manager), 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::TOOL_CALL,
        'request_id' => 'req-1',
        'tool_name' => 'handler_echo',
        'parameters' => ['val' => 'hi'],
        'call_id' => 'call-h1',
    ]);

    $response = $mh->handleMessage('conn-1', null, $rawMsg);

    expect($response)->toBeArray();
    expect($response['type'])->toBe(MessageTypes::TOOL_RESOLVE);
    expect($response['result'])->toBe(['echoed' => ['val' => 'hi'], 'via' => 'handler']);
});

test('handler-registered and closure-registered tools resolve identically through executeToolCall', function () {
    $registry = new ToolRegistry();
    $registry->register('closure_tool', 'Closure tool', ['type' => 'object'], fn ($p) => ['ok' => true, 'p' => $p]);
    $registry->registerHandler(new class extends \Tetrix\AiBridge\Tools\AbstractTool {
        public function name(): string
        {
            return 'handler_tool';
        }

        public function description(): string
        {
            return 'A handler tool returning the same shape as the closure tool.';
        }

        protected function defineParameters(): array
        {
            return [];
        }

        public function handle(array $params): mixed
        {
            return ['ok' => true, 'p' => $params];
        }
    });

    $mh = makeMessageHandler($this->manager, $registry);
    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', makeHandler($this->manager), 'user-1');

    $call = function (string $tool) use ($mh) {
        return $mh->handleMessage('conn-1', null, json_encode([
            'type' => MessageTypes::TOOL_CALL,
            'request_id' => 'req-1',
            'tool_name' => $tool,
            'parameters' => ['x' => 1],
            'call_id' => 'call-'.$tool,
        ]));
    };

    $closure = $call('closure_tool');
    $handler = $call('handler_tool');

    expect($closure['type'])->toBe(MessageTypes::TOOL_RESOLVE);
    expect($handler['type'])->toBe(MessageTypes::TOOL_RESOLVE);
    // Both paths produce a real result, not a tool_error.
    expect($closure['result'])->toBe(['ok' => true, 'p' => ['x' => 1]]);
    expect($handler['result'])->toBe(['ok' => true, 'p' => ['x' => 1]]);
});

test('a tool_call for a relayed request executes a ToolHandler-registered tool', function () {
    $registry = new ToolRegistry();
    $registry->registerHandler(new class extends \Tetrix\AiBridge\Tools\AbstractTool {
        public function name(): string
        {
            return 'relay_handler';
        }

        public function description(): string
        {
            return 'Handler tool exercised through the relay (serve) path.';
        }

        protected function defineParameters(): array
        {
            return [];
        }

        public function handle(array $params): mixed
        {
            return ['from' => 'relay_handler', 'params' => $params];
        }
    });

    $mh = makeMessageHandler($this->manager, $registry);
    $this->manager->addConnection('user-1', 'conn-1');
    $mh->registerRelayedRequest('req-relay-handler', 'user-1', 'conv-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::TOOL_CALL,
        'request_id' => 'req-relay-handler',
        'tool_name' => 'relay_handler',
        'parameters' => ['a' => 'b'],
        'call_id' => 'call-relay-h',
    ]);

    $response = $mh->handleMessage('conn-1', null, $rawMsg);

    expect($response)->toBeArray();
    expect($response['type'])->toBe(MessageTypes::TOOL_RESOLVE);
    expect($response['result'])->toBe(['from' => 'relay_handler', 'params' => ['a' => 'b']]);
});

test('executeToolCall injects the conversation into the shared ToolContext a handler reads', function () {
    // This is the regression guard for the singleton-ToolContext fix. A handler
    // tool that reads app(ToolContext::class) at execution time MUST observe the
    // conversation id that executeToolCall sets — which only holds if ToolContext
    // is a shared singleton. A fresh-per-resolution binding would leave the
    // handler's copy empty (the closure-vs-handler bug this fixes).
    $registry = new ToolRegistry();
    $registry->registerHandler(new class extends \Tetrix\AiBridge\Tools\AbstractTool {
        public function name(): string
        {
            return 'context_probe';
        }

        public function description(): string
        {
            return 'Returns the conversation id the runtime injected into ToolContext.';
        }

        protected function defineParameters(): array
        {
            return [];
        }

        public function handle(array $params): mixed
        {
            // Resolved fresh from the container — exactly how a consuming-app
            // handler (via its injected ActiveCampaign) would read it.
            return ['seen_conversation' => app(\Tetrix\AiBridge\Tools\ToolContext::class)->conversationId()];
        }
    });

    $mh = makeMessageHandler($this->manager, $registry);
    $this->manager->addConnection('user-1', 'conn-1');
    // makeHandler() sets the conversation id to 'test-conv'.
    $this->manager->registerPendingRequest('req-1', makeHandler($this->manager), 'user-1');

    $response = $mh->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::TOOL_CALL,
        'request_id' => 'req-1',
        'tool_name' => 'context_probe',
        'parameters' => [],
        'call_id' => 'call-ctx',
    ]));

    expect($response['type'])->toBe(MessageTypes::TOOL_RESOLVE);
    expect($response['result'])->toBe(['seen_conversation' => 'test-conv']);

    // And the context is cleared after the call (finally { forget() }).
    expect(app(\Tetrix\AiBridge\Tools\ToolContext::class)->conversationId())->toBeNull();
});

// --- providers_update: mid-connection provider sync ---

test('providers_update from an authenticated bridge refreshes the connection providers', function () {
    $this->manager->addConnection('user-1', 'conn-1', null, [
        ['name' => 'claude', 'available' => true],
    ]);

    $newProviders = [
        ['name' => 'claude', 'available' => true],
        ['name' => 'codex', 'available' => true],
    ];

    $rawMsg = json_encode([
        'type' => MessageTypes::PROVIDERS_UPDATE,
        'providers' => $newProviders,
    ]);

    $response = $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    // One-way notification: no response is sent.
    expect($response)->toBeNull();
    expect($this->manager->getProviders('user-1'))->toBe($newProviders);
});

test('providers_update before the hello handshake is ignored', function () {
    // No addConnection() — handshake never completed.
    $rawMsg = json_encode([
        'type' => MessageTypes::PROVIDERS_UPDATE,
        'providers' => [['name' => 'claude', 'available' => true]],
    ]);

    $response = $this->messageHandler->handleMessage('orphan-conn', null, $rawMsg);

    expect($response)->toBeNull();
});

// --- Token refresh: long-lived bridge tokens topped up at half-life ---

test('maybeRefreshToken returns null for connections without a cid claim', function () {
    // No cid recorded — this is a legacy user-scoped or pre-authenticated bridge.
    $this->manager->addConnection('user-1', 'conn-1');

    expect($this->messageHandler->maybeRefreshToken('user-1'))->toBeNull();
});

test('maybeRefreshToken returns null when token has more than half its life remaining', function () {
    $bridgeTtl = 30 * 24 * 3600;
    config(['ai-bridge.token.bridge_ttl' => $bridgeTtl]);

    // Token issued just now — full TTL ahead.
    $this->manager->addConnection('user-1', 'conn-1', null, [], time() + $bridgeTtl, 42);

    expect($this->messageHandler->maybeRefreshToken('user-1'))->toBeNull();
});

test('maybeRefreshToken issues a fresh token once the current one is past half its life', function () {
    $bridgeTtl = 30 * 24 * 3600;
    config(['ai-bridge.token.bridge_ttl' => $bridgeTtl]);

    // Token expires in less than half a TTL — refresh is due.
    $oldExpiresAt = time() + intdiv($bridgeTtl, 4);
    $this->manager->addConnection('user-1', 'conn-1', null, [], $oldExpiresAt, 42);

    $newToken = $this->messageHandler->maybeRefreshToken('user-1');

    expect($newToken)->toBeString();

    // The fresh token must carry the same cid claim and subject as the original.
    $decoded = app(TokenManager::class)->validate($newToken);
    expect((string) $decoded->sub)->toBe('user-1');
    expect((int) $decoded->cid)->toBe(42);

    // Recorded expiry advances so a back-to-back call returns null instead of churning.
    expect($this->manager->getTokenExpiresAt('user-1'))->toBeGreaterThan($oldExpiresAt);
    expect($this->messageHandler->maybeRefreshToken('user-1'))->toBeNull();
});

test('welcome response carries refreshed_token when the bridge token is past half-life', function () {
    $bridgeTtl = 30 * 24 * 3600;
    config(['ai-bridge.token.bridge_ttl' => $bridgeTtl]);

    // Pre-authenticate the connection with an aging token, so handleHello takes
    // the pre-auth path and still includes refreshed_token in the welcome.
    $this->manager->addConnection('user-1', 'conn-1', null, [], time() + 60, 99);

    $rawMsg = json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '0.1',
        'providers' => [],
    ]);

    $response = $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($response['type'])->toBe(MessageTypes::WELCOME);
    expect($response)->toHaveKey('refreshed_token');
    expect($response['refreshed_token'])->toBeString();
});

test('welcome response omits refreshed_token when the bridge token is fresh', function () {
    $bridgeTtl = 30 * 24 * 3600;
    config(['ai-bridge.token.bridge_ttl' => $bridgeTtl]);

    $this->manager->addConnection('user-1', 'conn-1', null, [], time() + $bridgeTtl, 99);

    $rawMsg = json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '0.1',
        'providers' => [],
    ]);

    $response = $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($response['type'])->toBe(MessageTypes::WELCOME);
    expect($response)->not->toHaveKey('refreshed_token');
});

// --- cli_isolation in welcome response ---

test('welcome response defaults cli_isolation to "isolated" when the config is unset', function () {
    config(['ai-bridge.cli.isolation' => null]);

    $token = app(TokenManager::class)->generate('user-1');
    $rawMsg = json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '0.1',
        'providers' => [],
        'token' => $token,
    ]);

    $response = $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($response['type'])->toBe(MessageTypes::WELCOME);
    expect($response['cli_isolation'])->toBe('isolated');
});

test('welcome response sends cli_isolation=native when the operator explicitly opts in', function () {
    config(['ai-bridge.cli.isolation' => 'native']);

    $token = app(TokenManager::class)->generate('user-1');
    $rawMsg = json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '0.1',
        'providers' => [],
        'token' => $token,
    ]);

    $response = $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($response['cli_isolation'])->toBe('native');
});

test('welcome response normalises unrecognised cli_isolation values back to "isolated"', function () {
    config(['ai-bridge.cli.isolation' => 'free-for-all']);

    $token = app(TokenManager::class)->generate('user-1');
    $rawMsg = json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '0.1',
        'providers' => [],
        'token' => $token,
    ]);

    $response = $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    // Typos / surprises default-safe, not default-leaky.
    expect($response['cli_isolation'])->toBe('isolated');
});

// --- ai_request_ack: the bridge's session defaults (bridge 0.12.0+) ---

test('a bridge that dropped env keys says so, and the server does not keep it to itself', function () {
    // The whole reason the bridge echoes what it resolved: so a disagreement
    // about what this protocol contains surfaces HERE, on the turn it
    // happened, rather than being inferred from the assistant behaving oddly
    // three turns later. Nothing else on this side ever mentions it.
    Log::spy();
    $this->manager->addConnection('user-1', 'conn-1');

    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::AI_REQUEST_ACK,
        'request_id' => 'req-1',
        'cli_session_id' => null,
        'bridge_session' => [
            'prompt_mode' => 'default',
            'prompt_server_text' => false,
            'env_overridden' => [],
            'env_rejected' => ['ANTHROPIC_BASE_URL'],
        ],
    ]));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'dropped env keys')
            && $context['env_rejected'] === ['ANTHROPIC_BASE_URL'])
        ->once();
});

test('a clean resolution is not a warning', function () {
    // A warning on every ordinary turn is how a real warning stops being read.
    Log::spy();
    $this->manager->addConnection('user-1', 'conn-1');

    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::AI_REQUEST_ACK,
        'request_id' => 'req-1',
        'cli_session_id' => null,
        'bridge_session' => [
            'prompt_mode' => 'append',
            'prompt_server_text' => true,
            'env_overridden' => ['CLAUDE_CODE_DISABLE_BACKGROUND_TASKS'],
            'env_rejected' => [],
        ],
    ]));

    Log::shouldNotHaveReceived('warning');
});

test('an older bridge omitting the field is not treated as a bridge that applied nothing', function () {
    // Absence means UNKNOWN. A bridge before 0.12.0 sends no bridge_session at
    // all, and reading that as "no defaults were applied" is the one conclusion
    // that is never safe to draw from silence.
    Log::spy();
    $this->manager->addConnection('user-1', 'conn-1');

    $response = $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::AI_REQUEST_ACK,
        'request_id' => 'req-1',
        'cli_session_id' => 'sess-abc',
    ]));

    expect($response)->toBeNull();
    Log::shouldNotHaveReceived('warning');
});

// --- Turn input: input_open on the ack, turn_input_ack, user_input, pending_inputs ---

/** A running turn owned by user-1, its stream buffer started as the web process starts it. */
function turnWithInput(BridgeConnectionManager $manager, ArrayStreamStore $store, string $rid = 'req-in'): StreamHandler
{
    app()->instance(StreamStoreContract::class, $store);
    $manager->addConnection('user-1', 'conn-1');
    $manager->addConnection('user-2', 'conn-2');

    $handler = makeHandler($manager);
    $manager->registerPendingRequest($rid, $handler, 'user-1');
    $store->start($rid, ['conversation_id' => 'conv-1']);

    return $handler;
}

test('an ack confirming input_open is recorded in the turn stream metadata', function () {
    $store = new ArrayStreamStore();
    turnWithInput($this->manager, $store);

    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::AI_REQUEST_ACK,
        'request_id' => 'req-in',
        'cli_session_id' => null,
        'input_open' => true,
    ]));

    // Kept beside what the web process wrote, not in place of it.
    expect($store->status('req-in')['metadata'])->toBe(['conversation_id' => 'conv-1', 'input_open' => true])
        ->and(app(\Tetrix\AiBridge\AiBridgeManager::class)->inputOpen('req-in'))->toBeTrue();
});

test('an ack without input_open leaves the turn closed to input', function (array $extra) {
    $store = new ArrayStreamStore();
    turnWithInput($this->manager, $store);

    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::AI_REQUEST_ACK,
        'request_id' => 'req-in',
        'cli_session_id' => null,
    ] + $extra));

    expect($store->status('req-in')['metadata'])->not->toHaveKey('input_open')
        ->and(app(\Tetrix\AiBridge\AiBridgeManager::class)->inputOpen('req-in'))->toBeFalse();
})->with([
    'an older bridge' => [[]],
    'false' => [['input_open' => false]],
    'a truthy string' => [['input_open' => 'yes']],
]);

test('another user\'s bridge cannot open a turn to input', function () {
    $store = new ArrayStreamStore();
    turnWithInput($this->manager, $store);

    $this->messageHandler->handleMessage('conn-2', null, json_encode([
        'type' => MessageTypes::AI_REQUEST_ACK,
        'request_id' => 'req-in',
        'input_open' => true,
    ]));

    expect($store->status('req-in')['metadata'])->not->toHaveKey('input_open');
});

test('a non-string request_id on an ack is ignored rather than thrown past the loop', function () {
    $store = new ArrayStreamStore();
    turnWithInput($this->manager, $store);

    $response = $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::AI_REQUEST_ACK,
        'request_id' => ['req-in'],
        'input_open' => true,
    ]));

    expect($response)->toBeNull()
        ->and($store->status('req-in')['metadata'])->not->toHaveKey('input_open');
});

test('a stream store that cannot merge metadata reports the input closed, and nothing breaks', function () {
    $inner = new ArrayStreamStore();
    // A driver written against StreamStoreContract alone, as an app's own might be.
    $store = new class($inner) implements StreamStoreContract {
        public function __construct(private ArrayStreamStore $inner) {}
        public function start(string $r, array $m = []): void { $this->inner->start($r, $m); }
        public function appendEvent(string $r, string $e, array $d): int { return $this->inner->appendEvent($r, $e, $d); }
        public function range(string $r, int $f = -1): array { return $this->inner->range($r, $f); }
        public function status(string $r): array { return $this->inner->status($r); }
        public function setAbort(string $r): void { $this->inner->setAbort($r); }
        public function isAborted(string $r): bool { return $this->inner->isAborted($r); }
        public function complete(string $r, string $s): void { $this->inner->complete($r, $s); }
        public function cleanup(string $r): void { $this->inner->cleanup($r); }
    };
    app()->instance(StreamStoreContract::class, $store);
    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-in', makeHandler($this->manager), 'user-1');
    $store->start('req-in');

    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::AI_REQUEST_ACK, 'request_id' => 'req-in', 'input_open' => true,
    ]));

    expect(app(\Tetrix\AiBridge\AiBridgeManager::class)->inputOpen('req-in'))->toBeFalse();
});

test('a turn is no longer open to input once it has ended', function () {
    $store = new ArrayStreamStore();
    turnWithInput($this->manager, $store);
    $store->mergeMetadata('req-in', ['input_open' => true]);

    $store->complete('req-in', 'completed');

    expect(app(\Tetrix\AiBridge\AiBridgeManager::class)->inputOpen('req-in'))->toBeFalse()
        ->and(app(\Tetrix\AiBridge\AiBridgeManager::class)->inputOpen('req-unknown'))->toBeFalse();
});

test('merging metadata into a turn nobody started stores nothing', function () {
    $store = new ArrayStreamStore();
    $store->mergeMetadata('req-ghost', ['input_open' => true]);

    expect($store->status('req-ghost')['status'])->toBe('not_found');
});

/** Deliver a turn_input_ack from a connection and return what the waiter was handed. */
function deliverTurnInputAck(MessageHandler $mh, BridgeConnectionManager $manager, array $frame, string $from = 'conn-1'): ?array
{
    $answer = null;
    $manager->registerPendingTurnInput('req-in', 'msg-1', 'user-1', function (array $a) use (&$answer) {
        $answer = $a;
    });

    $mh->handleMessage($from, null, json_encode($frame + [
        'type' => MessageTypes::TURN_INPUT_ACK,
        'request_id' => 'req-in',
        'message_id' => 'msg-1',
    ]));

    return $answer;
}

test('turn_input_ack hands the bridge answer to whoever is waiting', function (array $frame, array $expected) {
    $this->manager->addConnection('user-1', 'conn-1');

    expect(deliverTurnInputAck($this->messageHandler, $this->manager, $frame))->toBe($expected)
        ->and($this->manager->hasPendingTurnInput('req-in', 'msg-1'))->toBeFalse();
})->with([
    'accepted' => [['status' => 'accepted'], ['status' => 'accepted']],
    'turn not running' => [['status' => 'rejected', 'reason' => 'turn_not_running'], ['status' => 'rejected', 'reason' => 'turn_not_running']],
    'input not open' => [['status' => 'rejected', 'reason' => 'input_not_open'], ['status' => 'rejected', 'reason' => 'input_not_open']],
    // Never passed on: the application branches on the reason, and
    // turn_not_running is the one that makes it start a turn.
    'a reason nobody documented' => [['status' => 'rejected', 'reason' => 'lunar'], ['status' => 'rejected']],
    // Only `accepted` is acceptance; a confused frame is a refusal.
    'no status' => [[], ['status' => 'rejected']],
    'an accepted with a stray reason' => [['status' => 'accepted', 'reason' => 'turn_not_running'], ['status' => 'accepted']],
]);

test('turn_input_ack from another user\'s bridge is refused, and the message keeps waiting', function () {
    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');

    expect(deliverTurnInputAck($this->messageHandler, $this->manager, ['status' => 'rejected', 'reason' => 'turn_not_running'], 'conn-2'))->toBeNull()
        ->and($this->manager->hasPendingTurnInput('req-in', 'msg-1'))->toBeTrue();
});

test('turn_input_ack from a connection that never completed the handshake is ignored', function () {
    expect(deliverTurnInputAck($this->messageHandler, $this->manager, ['status' => 'accepted'], 'conn-nobody'))->toBeNull();
});

test('a malformed turn_input_ack is dropped rather than thrown past the loop', function () {
    $this->manager->addConnection('user-1', 'conn-1');

    foreach ([['request_id' => 7], ['message_id' => ['x']], ['request_id' => ''], ['status' => ['accepted']], ['reason' => 5]] as $bad) {
        $this->messageHandler->handleMessage('conn-1', null, json_encode($bad + [
            'type' => MessageTypes::TURN_INPUT_ACK, 'request_id' => 'req-in', 'message_id' => 'msg-1', 'status' => 'rejected',
        ]));
    }

    expect(true)->toBeTrue();
});

test('a message waiting on its ack is answered no_answer when the bridge disconnects', function () {
    $this->manager->addConnection('user-1', 'conn-1');
    $answer = null;
    $this->manager->registerPendingTurnInput('req-in', 'msg-1', 'user-1', function (array $a) use (&$answer) {
        $answer = $a;
    });

    $this->manager->removeConnection('user-1', 'bridge_closed');

    expect($answer)->toBe(['status' => 'rejected', 'reason' => 'no_answer']);
});

test('user_input and main_state reach the turn over the wire, only from its owner', function () {
    $store = new ArrayStreamStore();
    $handler = turnWithInput($this->manager, $store);

    $seen = [];
    $handler->onUserInput(function (array $data) use (&$seen) {
        $seen[] = ['user_input', $data];
    });
    $handler->onMainState(function (array $data) use (&$seen) {
        $seen[] = ['main_state', $data];
    });

    foreach (['conn-2', 'conn-1'] as $conn) {
        $this->messageHandler->handleMessage($conn, null, json_encode([
            'type' => MessageTypes::STREAM, 'request_id' => 'req-in', 'event' => MessageTypes::MAIN_STATE, 'data' => ['state' => 'idle'],
        ]));
        $this->messageHandler->handleMessage($conn, null, json_encode([
            'type' => MessageTypes::STREAM, 'request_id' => 'req-in', 'event' => MessageTypes::USER_INPUT, 'data' => ['message_id' => 'msg-1'],
        ]));
    }

    expect($seen)->toBe([['main_state', ['state' => 'idle']], ['user_input', ['message_id' => 'msg-1']]]);
});

test('a cancelled turn passes on which delivered messages were never read', function () {
    $store = new ArrayStreamStore();
    $handler = turnWithInput($this->manager, $store);

    $meta = null;
    $handler->onCancelled(function (string $reason, array $m = []) use (&$meta) {
        $meta = $m;
    });

    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::CANCELLED,
        'request_id' => 'req-in',
        'pending_inputs' => ['msg-2', 7, '', 'msg-3'],
    ]));

    expect($meta)->toBe(['pending_inputs' => ['msg-2', 'msg-3']]);
});

test('a cancelled turn without pending inputs, or with a malformed list, passes nothing on', function (mixed $pending) {
    $store = new ArrayStreamStore();
    $handler = turnWithInput($this->manager, $store);

    $meta = 'unset';
    $handler->onCancelled(function (string $reason, array $m = []) use (&$meta) {
        $meta = $m;
    });

    $frame = ['type' => MessageTypes::CANCELLED, 'request_id' => 'req-in'];
    if ($pending !== 'absent') {
        $frame['pending_inputs'] = $pending;
    }
    $this->messageHandler->handleMessage('conn-1', null, json_encode($frame));

    expect($meta)->toBe([]);
})->with(['absent', [[]], 'a string', [['a' => 'msg-1']]]);

// --- D09710: reliable delivery (numbered frames and stream_ack) ---

/** A pending turn of user-1 on conn-1 that records the deltas it is given and the frames sent to the bridge. */
function reliableRig(object $test): object
{
    $rig = new stdClass();
    $rig->deltas = [];
    $rig->sent = [];
    $rig->done = 0;
    $rig->handler = makeHandler($test->manager);
    $rig->handler->onBlockDelta(function (StreamEvent $event) use ($rig) {
        $rig->deltas[] = $event->data['content'] ?? null;
    });
    $rig->handler->onDone(function () use ($rig) {
        $rig->done++;
    });
    $test->manager->setSendCallback(function ($connection, array $payload) use ($rig): bool {
        $rig->sent[] = $payload;

        return true;
    });
    $test->manager->addConnection('user-1', 'conn-1');
    $test->manager->registerPendingRequest('req-1', $rig->handler, 'user-1');
    $rig->acks = fn (): array => array_values(array_map(
        fn (array $p) => $p['seq'],
        array_filter($rig->sent, fn (array $p) => $p['type'] === MessageTypes::STREAM_ACK),
    ));

    return $rig;
}

function numberedDelta(int $seq, string $content, string $rid = 'req-1'): string
{
    return json_encode([
        'type' => MessageTypes::STREAM, 'request_id' => $rid, 'seq' => $seq,
        'event' => MessageTypes::BLOCK_DELTA,
        'data' => ['block_type' => 'text', 'block_index' => 0, 'content' => $content],
    ]);
}

function numberedDone(int $seq, string $rid = 'req-1'): string
{
    return json_encode(['type' => MessageTypes::STREAM, 'request_id' => $rid, 'seq' => $seq, 'event' => MessageTypes::DONE, 'data' => []]);
}

test('numbered frames in order are all stored and acked, the final one at once', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    $rig = reliableRig($this);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDone(3));

    expect($rig->deltas)->toBe(['a', 'b'])
        ->and($rig->done)->toBe(1)
        ->and(($rig->acks)())->toBe([1, 2, 3])
        ->and($rig->sent[2])->toBe(['type' => 'stream_ack', 'request_id' => 'req-1', 'seq' => 3]);
});

test('a re-sent frame is dropped, not stored twice, and confirmed again', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    $rig = reliableRig($this);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(3, 'c'));

    expect($rig->deltas)->toBe(['a', 'b', 'c'])
        ->and(($rig->acks)())->toBe([1, 2, 2, 2, 3]);
});

test('a frame after a gap is not stored or acked, and the re-send in order fills it', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    Log::spy();
    $rig = reliableRig($this);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(3, 'c'));

    expect($rig->deltas)->toBe(['a'])
        ->and(($rig->acks)())->toBe([1])
        ->and($this->manager->lastStoredSeq('req-1'))->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, 'gap'))->once();

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(3, 'c'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDone(4));

    expect($rig->deltas)->toBe(['a', 'b', 'c'])
        ->and($rig->done)->toBe(1)
        ->and(($rig->acks)())->toBe([1, 2, 3, 4]);
});

test('acks are throttled while frames arrive, owed ones go out on the flush, the final at once', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 60]);
    $rig = reliableRig($this);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(3, 'c'));

    expect(($rig->acks)())->toBe([1]);

    expect($this->messageHandler->flushStreamAcks())->toBe(1)
        ->and(($rig->acks)())->toBe([1, 3])
        ->and($this->messageHandler->flushStreamAcks())->toBe(0);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(4, 'd'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDone(5));

    expect(($rig->acks)())->toBe([1, 3, 5])
        ->and($this->messageHandler->flushStreamAcks())->toBe(0);
});

test('a re-sent final frame of a turn already ended is confirmed again as closed, not settled as an orphan', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    $rig = reliableRig($this);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDone(2));
    $this->messageHandler->handleMessage('conn-1', null, numberedDone(2));

    expect($rig->done)->toBe(1)
        ->and(($rig->acks)())->toBe([1, 2, 2])
        ->and(end($rig->sent))->toBe(['type' => 'stream_ack', 'request_id' => 'req-1', 'seq' => 2, 'closed' => true]);
});

test('another user learns nothing of a turn through its final seq: answered as for any unknown turn', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    $rig = reliableRig($this);
    $this->manager->addConnection('user-2', 'conn-2');

    $this->messageHandler->handleMessage('conn-1', null, numberedDone(1));
    $rig->sent = [];
    $this->messageHandler->handleMessage('conn-2', null, numberedDone(1));
    $this->messageHandler->handleMessage('conn-2', null, numberedDelta(1, 'x'));
    $this->messageHandler->handleMessage('conn-2', null, numberedDelta(1, 'x', 'req-never-known'));

    $closed = fn (string $rid) => ['type' => 'stream_ack', 'request_id' => $rid, 'seq' => 1, 'closed' => true];
    expect($rig->sent)->toBe([$closed('req-1'), $closed('req-1'), $closed('req-never-known')])
        ->and($rig->deltas)->toBe([]);
});

test('an old bridge without seq is stored exactly as before and never acked', function () {
    $rig = reliableRig($this);

    foreach (['a', 'a', 'b'] as $content) {
        $this->messageHandler->handleMessage('conn-1', null, json_encode([
            'type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::BLOCK_DELTA,
            'data' => ['block_type' => 'text', 'block_index' => 0, 'content' => $content],
        ]));
    }
    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::DONE, 'data' => [],
    ]));

    expect($rig->deltas)->toBe(['a', 'a', 'b'])
        ->and($rig->done)->toBe(1)
        ->and($rig->sent)->toBe([]);
});

test('a numbered tool_call is run once and a re-send of it is not run again', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    $runs = 0;
    $registry = new ToolRegistry();
    $registry->register('echo', 'Echo test', ['type' => 'object'], function ($p) use (&$runs) {
        $runs++;

        return ['echoed' => $p];
    });
    $mh = makeMessageHandler($this->manager, $registry);
    $rig = reliableRig($this);

    $frame = json_encode([
        'type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'seq' => 1, 'event' => MessageTypes::TOOL_CALL,
        'data' => ['tool_name' => 'echo', 'parameters' => ['x' => 1], 'tool_call_id' => 'c-1'],
    ]);

    $first = $mh->handleMessage('conn-1', null, $frame);
    $again = $mh->handleMessage('conn-1', null, $frame);

    expect($first['type'] ?? null)->toBe(MessageTypes::TOOL_RESOLVE)
        ->and($again)->toBeNull()
        ->and($runs)->toBe(1)
        ->and(($rig->acks)())->toBe([1, 1]);
});

// --- D09710 rework: receipts for turns the server ended or does not hold ---

test('a frame whose processing throws is not counted as stored, so its re-send is stored (#14257)', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    $rig = reliableRig($this);
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start', 'cancel', 'markCompleted')->byDefault();
    $flaky = new class($provider) extends StreamHandler
    {
        public int $failuresLeft = 1;

        public array $stored = [];

        public function dispatchEvent(StreamEvent $event): void
        {
            if ($this->failuresLeft-- > 0) {
                throw new RuntimeException('store hiccup');
            }
            $this->stored[] = $event->data['content'] ?? null;
        }
    };
    $flaky->setMode(ProviderMode::Bridge);
    $this->manager->registerPendingRequest('req-2', $flaky, 'user-1');

    expect(fn () => $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a', 'req-2')))
        ->toThrow(RuntimeException::class);
    expect($this->manager->lastStoredSeq('req-2'))->toBe(0)
        ->and(($rig->acks)())->toBe([]);

    // The bridge re-sends it (it was never confirmed): stored now, not a duplicate.
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a', 'req-2'));

    expect($flaky->stored)->toBe(['a'])
        ->and($this->manager->lastStoredSeq('req-2'))->toBe(1)
        ->and(($rig->acks)())->toBe([1]);
});

test('a numbered frame of a turn the server never held is answered closed with its own seq, and still settled (#14260)', function () {
    $rig = reliableRig($this);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(4, 'x', 'req-gone'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDone(5, 'req-gone'));

    expect($rig->sent)->toBe([
        ['type' => 'stream_ack', 'request_id' => 'req-gone', 'seq' => 4, 'closed' => true],
        ['type' => 'stream_ack', 'request_id' => 'req-gone', 'seq' => 5, 'closed' => true],
    ]);
});

test('a stop seen on the heartbeat tells a reliable bridge the turn is closed, with the last stored seq (#14273)', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    $store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $store);
    $rig = reliableRig($this);
    $this->manager->setBridgeInfo('user-1', ['capabilities' => ['reliable_delivery']]);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b'));
    $store->setAbort('req-1');
    $this->messageHandler->handleMessage('conn-1', null, json_encode(['type' => MessageTypes::PING, 'timestamp' => 1]));

    expect(array_slice($rig->sent, 2))->toBe([
        ['type' => MessageTypes::CANCEL, 'request_id' => 'req-1'],
        ['type' => 'stream_ack', 'request_id' => 'req-1', 'seq' => 2, 'closed' => true],
    ]);

    // The bridge's own ending after the cancel is confirmed with its own seq, still closed.
    $rig->sent = [];
    $this->messageHandler->handleMessage('conn-1', null, numberedDone(3));
    expect($rig->sent)->toBe([['type' => 'stream_ack', 'request_id' => 'req-1', 'seq' => 3, 'closed' => true]]);
});

test('a stop seen on a numbered frame is confirmed once, closed, with that frame\'s seq (#14273)', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    $store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $store);
    $rig = reliableRig($this);
    $this->manager->setBridgeInfo('user-1', ['capabilities' => ['reliable_delivery']]);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $store->setAbort('req-1');
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDone(3));

    $closed = array_values(array_filter($rig->sent, fn (array $p) => ($p['closed'] ?? false) === true));
    expect($rig->deltas)->toBe(['a'])
        ->and(array_column($closed, 'seq'))->toBe([2, 3]);
});

test('a stop on the heartbeat sends no closed stream_ack to a bridge without reliable_delivery', function () {
    $store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $store);
    $rig = reliableRig($this);
    $store->setAbort('req-1');

    $this->messageHandler->handleMessage('conn-1', null, json_encode(['type' => MessageTypes::PING, 'timestamp' => 1]));

    expect(array_column($rig->sent, 'type'))->toBe([MessageTypes::CANCEL]);
});

test('after a closed stream_ack for a Stop, the bridge\'s unnumbered cancelled records its unread messages and is never answered', function (bool $onFrame) {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    Log::spy();
    $store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $store);
    $rig = reliableRig($this);
    $this->manager->setBridgeInfo('user-1', ['capabilities' => ['reliable_delivery']]);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $store->setAbort('req-1');
    $this->messageHandler->handleMessage('conn-1', null, $onFrame
        ? numberedDelta(2, 'b')
        : json_encode(['type' => MessageTypes::PING, 'timestamp' => 1]));
    expect(collect($rig->sent)->where('closed', true))->toHaveCount(1);
    $rig->sent = [];

    $reply = $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::CANCELLED, 'request_id' => 'req-1', 'pending_inputs' => ['m-1', 'm-2'],
    ]));

    expect($reply)->toBeNull()
        ->and($rig->sent)->toBe([]);
    Event::assertDispatched(\Tetrix\AiBridge\Events\TurnInputsReturned::class, fn ($e) => $e->requestId === 'req-1'
        && $e->pendingInputs === ['m-1', 'm-2'] && (string) $e->userId === 'user-1');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
})->with(['stop seen on a frame' => true, 'stop seen on the heartbeat' => false]);

test('an unnumbered cancelled without pending_inputs for a turn the server closed is ignored quietly', function () {
    Log::spy();
    $store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $store);
    $rig = reliableRig($this);
    $this->manager->setBridgeInfo('user-1', ['capabilities' => ['reliable_delivery']]);
    $store->setAbort('req-1');
    $this->messageHandler->handleMessage('conn-1', null, json_encode(['type' => MessageTypes::PING, 'timestamp' => 1]));
    $rig->sent = [];

    $reply = $this->messageHandler->handleMessage('conn-1', null, json_encode(['type' => MessageTypes::CANCELLED, 'request_id' => 'req-1']));

    expect($reply)->toBeNull()->and($rig->sent)->toBe([]);
    Event::assertNotDispatched(\Tetrix\AiBridge\Events\TurnInputsReturned::class);
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

// --- D09710 review follow-up: a frame is confirmed only once it is in the stream store ---

/** The real store, failing the appends (or completions) it is told to; a BufferingSink writes into it. */
final class FlakyStreamStore implements StreamStoreContract
{
    public ArrayStreamStore $inner;

    /** Fail this many appends from now on, then work again. */
    public int $failAppends = 0;

    /** Fail every write until set back. */
    public bool $down = false;

    public function __construct()
    {
        $this->inner = new ArrayStreamStore();
    }

    public function start(string $requestId, array $metadata = []): void
    {
        $this->inner->start($requestId, $metadata);
    }

    public function appendEvent(string $requestId, string $eventName, array $data): int
    {
        if ($this->down || $this->failAppends > 0) {
            $this->failAppends = max(0, $this->failAppends - 1);
            throw new RuntimeException('redis went away');
        }

        return $this->inner->appendEvent($requestId, $eventName, $data);
    }

    public function range(string $requestId, int $fromIndex = -1): array
    {
        return $this->inner->range($requestId, $fromIndex);
    }

    public function status(string $requestId): array
    {
        return $this->inner->status($requestId);
    }

    public function setAbort(string $requestId): void
    {
        $this->inner->setAbort($requestId);
    }

    public function isAborted(string $requestId): bool
    {
        return $this->inner->isAborted($requestId);
    }

    public function complete(string $requestId, string $status): void
    {
        if ($this->down) {
            throw new RuntimeException('redis went away');
        }
        $this->inner->complete($requestId, $status);
    }

    public function cleanup(string $requestId): void
    {
        $this->inner->cleanup($requestId);
    }

    /** @return list<string|null> the buffered deltas, in order */
    public function deltas(string $requestId): array
    {
        return array_values(array_map(
            fn (array $e) => $e['data']['content'] ?? null,
            array_filter($this->inner->range($requestId), fn (array $e) => $e['event'] === MessageTypes::BLOCK_DELTA),
        ));
    }
}

/** reliableRig() whose turn's handler writes, through a real BufferingSink, into a FlakyStreamStore. */
function bufferedRig(object $test): object
{
    $rig = reliableRig($test);
    $rig->store = new FlakyStreamStore();
    app()->instance(StreamStoreContract::class, $rig->store);
    \Tetrix\AiBridge\Streaming\BufferingSink::attach($rig->handler, $rig->store);
    // The buffer is keyed by the handler's own id (the rig registers it as req-1).
    $rig->rid = $rig->handler->requestId;
    $test->manager->setBridgeInfo('user-1', ['capabilities' => ['reliable_delivery']]);

    return $rig;
}

test('a frame whose store write failed is not confirmed, and is written, in order, before the next (review point 1)', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    $rig = bufferedRig($this);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $rig->store->failAppends = 1;
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b'));

    // Not in the store: not confirmed, so the bridge keeps its copy.
    expect(($rig->acks)())->toBe([1])
        ->and($rig->store->deltas($rig->rid))->toBe(['a']);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(3, 'c'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDone(4));

    expect($rig->store->deltas($rig->rid))->toBe(['a', 'b', 'c'])
        ->and(($rig->acks)())->toBe([1, 3, 4])
        // Processed once: the stream's own listeners saw each delta once.
        ->and($rig->deltas)->toBe(['a', 'b', 'c'])
        ->and($rig->store->status($rig->rid)['status'])->toBe('completed');
});

test('an unstored frame is written and confirmed on the tick, or on the bridge\'s re-send, without being processed again (review point 1)', function (bool $onResend) {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    $rig = bufferedRig($this);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $rig->store->down = true;
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b'));
    $this->messageHandler->tickUnconfirmedFrames();
    expect(($rig->acks)())->toBe([1]);

    $rig->store->down = false;
    if ($onResend) {
        $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b'));
    } else {
        $this->messageHandler->tickUnconfirmedFrames();
    }

    expect($rig->store->deltas($rig->rid))->toBe(['a', 'b'])
        ->and(($rig->acks)())->toBe([1, 2])
        ->and($rig->deltas)->toBe(['a', 'b']);
})->with(['on the tick' => false, 'on the re-send' => true]);

test('a final frame whose store write failed is confirmed only once it is written (review point 1)', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    $rig = bufferedRig($this);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $rig->store->down = true;
    $this->messageHandler->handleMessage('conn-1', null, numberedDone(2));

    expect($rig->done)->toBe(1)
        ->and(($rig->acks)())->toBe([1]);

    // Re-sent while the store is still down: still not confirmed.
    $this->messageHandler->handleMessage('conn-1', null, numberedDone(2));
    expect(($rig->acks)())->toBe([1]);

    $rig->store->down = false;
    $this->messageHandler->tickUnconfirmedFrames();

    expect(($rig->acks)())->toBe([1, 2])
        ->and($rig->done)->toBe(1)
        ->and($rig->store->status($rig->rid)['status'])->toBe('completed');
});

test('a turn whose output cannot be stored ends with a visible error after the stall bound, and the bridge is told it is closed (review points 1, 2)', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0, 'ai-bridge.websocket.stream_stall_seconds' => 60]);
    $rig = bufferedRig($this);
    $errors = [];
    $rig->handler->onError(function (string $code, string $message) use (&$errors) {
        $errors[] = $code;
    });

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $rig->store->down = true;
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b'));

    $this->messageHandler->tickUnconfirmedFrames(time() + 30);
    expect($errors)->toBe([])
        ->and($this->manager->getPendingRequest('req-1'))->not->toBeNull();

    $this->messageHandler->tickUnconfirmedFrames(time() + 61);

    expect($errors)->toBe(['stream_stalled'])
        ->and($this->manager->getPendingRequest('req-1'))->toBeNull()
        ->and(end($rig->sent))->toBe(['type' => 'stream_ack', 'request_id' => 'req-1', 'seq' => 1, 'closed' => true]);
});

// --- D09710 review follow-up: a gap on a live connection does not hang the turn ---

/** A pending turn req-2 of user-1 whose handler throws on the frames it is told to. */
function throwingTurn(object $test): object
{
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start', 'cancel', 'markCompleted')->byDefault();
    $turn = new class($provider, 'req-2') extends StreamHandler
    {
        /** @var list<string> */
        public array $throwOn = [];

        public array $stored = [];

        public array $errors = [];

        public function dispatchEvent(StreamEvent $event): void
        {
            $content = $event->data['content'] ?? null;
            if (in_array($content, $this->throwOn, true)) {
                throw new RuntimeException('store hiccup');
            }
            if ($event->event === MessageTypes::BLOCK_DELTA) {
                $this->stored[] = $content;
            }
            parent::dispatchEvent($event);
        }
    };
    $turn->setMode(ProviderMode::Bridge);
    $turn->onError(function (string $code) use ($turn) {
        $turn->errors[] = $code;
    });
    $test->manager->registerPendingRequest('req-2', $turn, 'user-1');

    return $turn;
}

test('a gap on a live connection is filled by the bridge\'s re-send, without a reconnect (review point 2)', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0]);
    $rig = reliableRig($this);
    $turn = throwingTurn($this);
    $turn->throwOn = ['b'];

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a', 'req-2'));
    expect(fn () => $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b', 'req-2')))->toThrow(RuntimeException::class);
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(3, 'c', 'req-2'));
    $this->messageHandler->handleMessage('conn-1', null, numberedDone(4, 'req-2'));
    expect($turn->stored)->toBe(['a'])->and(($rig->acks)())->toBe([1]);

    // Still connected: the bridge re-sends what was not confirmed (ai-bridge, live re-send).
    $turn->throwOn = [];
    foreach ([numberedDelta(2, 'b', 'req-2'), numberedDelta(3, 'c', 'req-2'), numberedDone(4, 'req-2')] as $frame) {
        $this->messageHandler->handleMessage('conn-1', null, $frame);
    }

    expect($turn->stored)->toBe(['a', 'b', 'c'])
        ->and(($rig->acks)())->toBe([1, 2, 3, 4])
        ->and($this->manager->getPendingRequest('req-2'))->toBeNull()
        ->and($turn->errors)->toBe([]);
});

test('a gap that is never filled ends the turn with a visible error after the stall bound, instead of hanging (review point 2)', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0, 'ai-bridge.websocket.stream_stall_seconds' => 60]);
    $rig = reliableRig($this);
    $this->manager->setBridgeInfo('user-1', ['capabilities' => ['reliable_delivery']]);
    $turn = throwingTurn($this);
    $turn->throwOn = ['b'];

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a', 'req-2'));
    // The frame keeps failing, however often it is re-sent.
    foreach ([1, 2] as $attempt) {
        expect(fn () => $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b', 'req-2')))->toThrow(RuntimeException::class);
        $this->messageHandler->handleMessage('conn-1', null, numberedDone(3, 'req-2'));
    }

    $this->messageHandler->tickUnconfirmedFrames(time() + 30);
    expect($turn->errors)->toBe([]);

    $this->messageHandler->tickUnconfirmedFrames(time() + 61);

    expect($turn->errors)->toBe(['stream_stalled'])
        ->and($this->manager->getPendingRequest('req-2'))->toBeNull()
        ->and(end($rig->sent))->toBe(['type' => 'stream_ack', 'request_id' => 'req-2', 'seq' => 1, 'closed' => true]);
});

test('a detached turn is not ended for a stall: the grace period bounds it (review point 2)', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0, 'ai-bridge.websocket.stream_stall_seconds' => 60]);
    $rig = reliableRig($this);
    $this->manager->setBridgeInfo('user-1', ['capabilities' => ['reliable_delivery']]);
    $turn = throwingTurn($this);
    $turn->throwOn = ['b'];
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a', 'req-2'));
    expect(fn () => $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b', 'req-2')))->toThrow(RuntimeException::class);

    $this->manager->removeConnection('user-1', 'websocket_closed');
    $this->messageHandler->tickUnconfirmedFrames(time() + 61);

    expect($turn->errors)->toBe([])
        ->and($this->manager->isDetached('req-2'))->toBeTrue();
});

// --- D09710 CodeRabbit: a deferred terminal write is owned on every cleanup path ---

test('a turn that ends while its store writes are kept is finished in the store once it is back, on every ending path (CodeRabbit 4537573143)', function (string $frame, string $status) {
    $rig = bufferedRig($this);

    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::BLOCK_DELTA,
        'data' => ['block_type' => 'text', 'block_index' => 0, 'content' => 'a'],
    ]));
    $rig->store->down = true;
    $this->messageHandler->handleMessage('conn-1', null, $frame);

    expect($this->manager->getPendingRequest('req-1'))->toBeNull()
        ->and($rig->store->status($rig->rid)['status'])->toBe('streaming');

    $this->messageHandler->tickUnconfirmedFrames();
    expect($rig->store->status($rig->rid)['status'])->toBe('streaming');

    $rig->store->down = false;
    $this->messageHandler->tickUnconfirmedFrames();

    // MUTATION: drop the ended-turn sweep in tickUnconfirmedFrames() and this stays streaming.
    expect($rig->store->status($rig->rid)['status'])->toBe($status)
        ->and(array_column($rig->store->inner->range($rig->rid), 'event'))->toBe([MessageTypes::BLOCK_DELTA, json_decode($frame, true)['event'] ?? MessageTypes::CANCELLED])
        ->and($this->manager->unstoredHandlers())->toBe([]);
})->with([
    'an unnumbered done' => [json_encode(['type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::DONE, 'data' => []]), 'completed'],
    'an unnumbered error' => [json_encode(['type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::ERROR, 'data' => ['code' => 'x', 'message' => 'y']]), 'failed'],
    'a top-level cancelled' => [json_encode(['type' => MessageTypes::CANCELLED, 'request_id' => 'req-1']), 'cancelled'],
]);

test('a stalled turn ended while the store is down is marked failed once the store is back (CodeRabbit 4537573143)', function () {
    config(['ai-bridge.websocket.stream_ack_interval' => 0, 'ai-bridge.websocket.stream_stall_seconds' => 60]);
    $rig = bufferedRig($this);

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $rig->store->down = true;
    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(2, 'b'));
    $this->messageHandler->tickUnconfirmedFrames(time() + 61);

    expect($this->manager->getPendingRequest('req-1'))->toBeNull()
        ->and($rig->store->status($rig->rid)['status'])->toBe('streaming');

    $rig->store->down = false;
    $this->messageHandler->tickUnconfirmedFrames(time() + 62);

    expect($rig->store->status($rig->rid)['status'])->toBe('failed')
        ->and($rig->store->deltas($rig->rid))->toBe(['a', 'b'])
        ->and(collect($rig->store->inner->range($rig->rid))->last()['data']['code'] ?? null)->toBe('stream_stalled');
});

test('when the store keeps refusing an ended turn\'s output, the rest is given up after the stall bound but the turn is still marked finished (CodeRabbit 4537573143)', function () {
    config(['ai-bridge.websocket.stream_stall_seconds' => 60]);
    $rig = bufferedRig($this);

    // Appends keep failing; the status write works.
    $rig->store->start($rig->rid);
    $rig->store->failAppends = PHP_INT_MAX;
    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::BLOCK_DELTA,
        'data' => ['block_type' => 'text', 'block_index' => 0, 'content' => 'a'],
    ]));
    $this->messageHandler->handleMessage('conn-1', null, json_encode(['type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::DONE, 'data' => []]));

    $this->messageHandler->tickUnconfirmedFrames(time());
    $this->messageHandler->tickUnconfirmedFrames(time() + 30);
    expect($rig->store->status($rig->rid)['status'])->toBe('streaming');

    $this->messageHandler->tickUnconfirmedFrames(time() + 61);

    expect($rig->store->status($rig->rid)['status'])->toBe('completed')
        ->and($this->manager->unstoredHandlers())->toBe([]);
});

// --- D09710 CodeRabbit: the kept store writes of a turn are bounded ---

test('a turn whose kept store writes reach their bound stops keeping more and is ended at once, not after the stall bound (CodeRabbit 4537573154)', function () {
    config([
        'ai-bridge.websocket.stream_ack_interval' => 0,
        'ai-bridge.websocket.stream_stall_seconds' => 60,
        'ai-bridge.websocket.stream_store_backlog_bytes' => 2000,
    ]);
    $rig = bufferedRig($this);
    $errors = [];
    $rig->handler->onError(function (string $code) use (&$errors) {
        $errors[] = $code;
    });

    $this->messageHandler->handleMessage('conn-1', null, numberedDelta(1, 'a'));
    $rig->store->down = true;
    for ($seq = 2; $seq <= 10; $seq++) {
        $this->messageHandler->handleMessage('conn-1', null, numberedDelta($seq, str_repeat('x', 500)));
    }

    // MUTATION: drop the bound in StreamHandler::deferStore() and nothing ends before the stall bound.
    $this->messageHandler->tickUnconfirmedFrames(time());

    expect($errors)->toBe(['stream_stalled'])
        ->and($this->manager->getPendingRequest('req-1'))->toBeNull()
        ->and(end($rig->sent))->toBe(['type' => 'stream_ack', 'request_id' => 'req-1', 'seq' => 1, 'closed' => true]);

    // Its end is still written once the store is back.
    $rig->store->down = false;
    $this->messageHandler->tickUnconfirmedFrames(time() + 1);
    expect($rig->store->status($rig->rid)['status'])->toBe('failed');
});

test('a turn on a bridge without reliable_delivery drops a failed store write as before: it is not ended, sent no stream_ack, and later writes land once the store is back (D09710 review)', function () {
    config([
        'ai-bridge.websocket.stream_ack_interval' => 0,
        'ai-bridge.websocket.stream_stall_seconds' => 60,
        'ai-bridge.websocket.stream_store_backlog_writes' => 3,
    ]);
    $rig = bufferedRig($this);
    $this->manager->setBridgeInfo('user-1', ['capabilities' => []]);
    $errors = [];
    $rig->handler->onError(function (string $code) use (&$errors) {
        $errors[] = $code;
    });
    $unnumbered = fn (string $content): string => json_encode([
        'type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::BLOCK_DELTA,
        'data' => ['block_type' => 'text', 'block_index' => 0, 'content' => $content],
    ]);

    $this->messageHandler->handleMessage('conn-1', null, $unnumbered('a'));
    $rig->store->down = true;
    for ($i = 1; $i <= 10; $i++) {
        $this->messageHandler->handleMessage('conn-1', null, $unnumbered("lost{$i}"));
    }

    // MUTATION: drop the reliable_delivery check in tickUnconfirmedFrames() / BufferingSink and the turn is ended.
    $this->messageHandler->tickUnconfirmedFrames(time());
    $this->messageHandler->tickUnconfirmedFrames(time() + 61);

    expect($errors)->toBe([])
        ->and($this->manager->getPendingRequest('req-1'))->toBe($rig->handler)
        ->and($rig->handler->hasUnstored())->toBeFalse()
        ->and(array_filter($rig->sent, fn (array $p) => $p['type'] === MessageTypes::STREAM_ACK))->toBe([]);

    // The store is back: what comes next is stored, and the turn finishes.
    $rig->store->down = false;
    $this->messageHandler->handleMessage('conn-1', null, $unnumbered('b'));
    $this->messageHandler->handleMessage('conn-1', null, json_encode(['type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::DONE, 'data' => []]));

    expect($rig->store->deltas($rig->rid))->toBe(['a', 'b'])
        ->and($rig->store->status($rig->rid)['status'])->toBe('completed')
        ->and($rig->done)->toBe(1)
        ->and(array_filter($rig->sent, fn (array $p) => $p['type'] === MessageTypes::STREAM_ACK))->toBe([]);
});
