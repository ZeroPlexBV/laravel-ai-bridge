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
    expect($all)->toHaveCount(28);
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
