<?php

declare(strict_types=1);

use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\BufferingSink;
use Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore;
use Tetrix\AiBridge\Streaming\StreamHandler;

/*
|--------------------------------------------------------------------------
| BufferingSink tests
|--------------------------------------------------------------------------
|
| Verifies the contract between StreamHandler events and the stream-event
| buffer the SSE tail reads. Thinking-block suppression matches the
| broadcast/SSE wiring so what the buffer carries matches what a UI replay
| expects to see.
|
*/

function fakeBufferProvider(): StreamableProvider
{
    return new class implements StreamableProvider {
        public function setConversationId(string $c): static { return $this; }
        public function setMessage(string $m): static { return $this; }
        public function setOptions(array $o): static { return $this; }
        public function start(): void {}
        public function cancel(): void {}
        public function markCompleted(): void {}
        public function getStreamHandler(): StreamHandler { throw new RuntimeException('n/a'); }
    };
}

test('attach() routes every block event into the buffer in order', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-1');
    BufferingSink::attach($handler, $store);

    $handler->dispatchBlockStart(BlockType::Text, 0);
    $handler->dispatchBlockDelta(BlockType::Text, 0, 'hi');
    $handler->dispatchBlockStop(BlockType::Text, 0);

    $events = $store->range('rid-1');
    expect(array_column($events, 'event'))->toBe(['block_start', 'block_delta', 'block_stop']);
    expect($events[1]['data']['content'])->toBe('hi');
});

test('attach() suppresses thinking blocks when configured (default)', function () {
    config()->set('ai-bridge.streaming.suppress_thinking_blocks', true);

    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-think');
    BufferingSink::attach($handler, $store);

    $handler->dispatchBlockStart(BlockType::Thinking, 0);
    $handler->dispatchBlockDelta(BlockType::Thinking, 0, 'reasoning');
    $handler->dispatchBlockStop(BlockType::Thinking, 0);
    $handler->dispatchBlockStart(BlockType::Text, 1);
    $handler->dispatchBlockDelta(BlockType::Text, 1, 'visible');
    $handler->dispatchBlockStop(BlockType::Text, 1);

    $events = $store->range('rid-think');
    expect(array_column($events, 'event'))->toBe(['block_start', 'block_delta', 'block_stop']);
    expect($events[1]['data']['content'])->toBe('visible');
});

test('attach() forwards thinking blocks when suppression is off', function () {
    config()->set('ai-bridge.streaming.suppress_thinking_blocks', false);

    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-think-2');
    BufferingSink::attach($handler, $store);

    $handler->dispatchBlockStart(BlockType::Thinking, 0);
    $handler->dispatchBlockDelta(BlockType::Thinking, 0, 'r');
    $handler->dispatchBlockStop(BlockType::Thinking, 0);

    $events = $store->range('rid-think-2');
    expect($events)->toHaveCount(3);
});

test('attach() flips status to completed on done', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-done');
    BufferingSink::attach($handler, $store);

    $handler->dispatchDone(['total_tokens' => 5]);

    expect($store->status('rid-done')['status'])->toBe('completed');
    $events = $store->range('rid-done');
    expect(end($events)['data']['usage'])->toBe(['total_tokens' => 5]);
});

test('attach() flips status to failed on error', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-fail');
    BufferingSink::attach($handler, $store);

    $handler->dispatchError('boom', 'something exploded');

    expect($store->status('rid-fail')['status'])->toBe('failed');
});

test('attach() flips status to cancelled on cancelled', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-cancel');
    BufferingSink::attach($handler, $store);

    $handler->dispatchCancelled('user');

    expect($store->status('rid-cancel')['status'])->toBe('cancelled');
});

test('attach() buffers tool_call as a single event', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-tool');
    BufferingSink::attach($handler, $store);

    $handler->dispatchToolCall('search', ['q' => 'cats'], 'c-1');

    $events = $store->range('rid-tool');
    expect($events)->toHaveCount(1);
    expect($events[0]['event'])->toBe('tool_call');
    expect($events[0]['data'])->toBe([
        'tool_name' => 'search',
        'parameters' => ['q' => 'cats'],
        'tool_call_id' => 'c-1',
    ]);
});

test('the buffer carries a tool result, including whether it failed', function () {
    // Without a tool_result handler here, a result could never reach a browser
    // even once the bridge started sending them.
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-tr');
    BufferingSink::attach($handler, $store);

    $handler->dispatchToolResult('toolu_1', 'boom', true);

    $events = $store->range('rid-tr');

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('tool_result')
        ->and($events[0]['data']['result'])->toBe('boom')
        ->and($events[0]['data']['is_error'])->toBeTrue();
});

test('a null tool result keeps its key, so the call does not look forever-running', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-null');
    BufferingSink::attach($handler, $store);

    $handler->dispatchToolResult('toolu_1', null, true);

    expect($store->range('rid-null')[0]['data'])->toHaveKey('result');
});

test('the buffer carries rate limit status without ending the turn', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-rl');
    BufferingSink::attach($handler, $store);

    $handler->dispatchRateLimit('claude', ['status' => 'allowed']);

    $events = $store->range('rid-rl');

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('rate_limit')
        ->and($events[0]['data']['provider'])->toBe('claude');
});

test('a browser sees the documented turn metadata and not the session handle', function () {
    // An allowlist, not a denylist: `done` carries whatever the provider chose
    // to report, and a denylist forwards every future field by default —
    // including one nobody has evaluated yet.
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-meta');
    BufferingSink::attach($handler, $store);

    $handler->dispatchDone(['input_tokens' => 1], [
        'model' => 'claude-sonnet-5',
        'cost_usd' => 0.01,
        'cli_session_id' => 'sess-secret',
        'some_future_field' => 'not yet evaluated',
    ]);

    $done = collect($store->range('rid-meta'))->firstWhere('event', 'done');

    expect($done['data']['model'])->toBe('claude-sonnet-5')
        ->and($done['data']['cost_usd'])->toBe(0.01)
        ->and($done['data'])->not->toHaveKey('cli_session_id')
        ->and($done['data'])->not->toHaveKey('some_future_field');
});

test('a browser is told why a turn that produced nothing ended', function () {
    // The one thing a chat can show about an empty answer. `stop_reason` is
    // null on several of the paths that produce one, so with `subtype` filtered
    // out the UI had a blank message and no way to say anything about it.
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-empty');
    BufferingSink::attach($handler, $store);

    $handler->dispatchDone(null, [
        'subtype' => 'error_max_turns',
        'stop_reason' => null,
        'num_turns' => 9,
    ]);

    $done = collect($store->range('rid-empty'))->firstWhere('event', 'done');

    expect($done['data']['subtype'])->toBe('error_max_turns');
});

/*
|--------------------------------------------------------------------------
| Helper (sub-agent) activity
|--------------------------------------------------------------------------
*/

test('the buffer carries each helper task event whole, and replays it from an index', function () {
    $store = new ArrayStreamStore();
    $store->start('rid-task', []);
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-task');
    BufferingSink::attach($handler, $store);

    $started = [
        'phase' => 'started', 'task_id' => 'af2e', 'tool_use_id' => 'toolu_agent',
        'task_type' => 'local_agent', 'subagent_type' => 'general-purpose',
        'description' => 'Run the migration dry-run', 'spawn_depth' => 1, 'is_backgrounded' => true,
    ];
    $progress = [
        'phase' => 'progress', 'task_id' => 'af2e', 'tool_use_id' => 'toolu_agent',
        'last_tool_name' => 'Bash',
        'usage' => ['total_tokens' => 23921, 'tool_uses' => 1, 'duration_ms' => 2976],
        // Not in the protocol today; the buffer must not be the place it dies.
        'some_future_field' => 'kept',
    ];

    $handler->dispatchTask($started);
    $handler->dispatchBlockStart(BlockType::Text, 0);
    $handler->dispatchTask($progress);

    $all = $store->range('rid-task');
    expect(array_column($all, 'event'))->toBe(['task', 'block_start', 'task'])
        ->and($all[0]['data'])->toBe($started)
        ->and($all[2]['data'])->toBe($progress);

    // A browser that reconnects after the first event resumes from its index
    // and still receives the helper's later state, byte for byte.
    $resumed = $store->range('rid-task', $all[0]['index']);
    expect(array_column($resumed, 'event'))->toBe(['block_start', 'task'])
        ->and($resumed[1]['data'])->toBe($progress);

    // Non-terminal: the turn is still running.
    expect($store->status('rid-task')['status'])->toBe('streaming');
});

test('the buffer says which helper a tool result belongs to, and nothing for the main assistant', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-parent');
    BufferingSink::attach($handler, $store);

    $handler->dispatchEvent(StreamEvent::fromArray([
        'type' => 'stream', 'request_id' => 'rid-parent', 'event' => 'tool_result',
        'data' => ['tool_call_id' => 'toolu_child', 'result' => 'helper-done', 'is_error' => false, 'parent_tool_use_id' => 'toolu_agent'],
    ]));
    $handler->dispatchToolResult('toolu_main', 'launched');

    $events = $store->range('rid-parent');
    expect($events[0]['data'])->toBe(['tool_call_id' => 'toolu_child', 'result' => 'helper-done', 'is_error' => false, 'parent_tool_use_id' => 'toolu_agent'])
        ->and($events[1]['data'])->toBe(['tool_call_id' => 'toolu_main', 'result' => 'launched']);
});

test('the buffer keeps the parent on a helper block_start', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-bs');
    BufferingSink::attach($handler, $store);

    $handler->dispatchBlockStart(BlockType::ToolCall, 2, 'Bash', 'toolu_child', 'toolu_agent');

    expect($store->range('rid-bs')[0]['data']['parent_tool_use_id'])->toBe('toolu_agent');
});

test('a browser is shown the turn helper totals', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-stats');
    BufferingSink::attach($handler, $store);

    $stats = ['spawned' => 1, 'started_in_background' => 1, 'completed' => 1, 'by_type' => ['general-purpose' => 1]];
    $handler->dispatchDone(null, ['subagent_stats' => $stats, 'cli_session_id' => 'sess-secret']);

    $done = collect($store->range('rid-stats'))->firstWhere('event', 'done');
    expect($done['data']['subagent_stats'])->toBe($stats)
        ->and($done['data'])->not->toHaveKey('cli_session_id');
});

test('the direct SSE path carries helper activity exactly as the buffer does', function () {
    // AiBridgeManager::wireCallbacks powers streamToResponse(); a field reaching
    // the buffer and not this path would reach one consumer and not the other.
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-sse');
    BufferingSink::attach($handler, $store);

    $sent = [];
    $wire = new ReflectionMethod(\Tetrix\AiBridge\AiBridgeManager::class, 'wireCallbacks');
    $wire->invoke(app(\Tetrix\AiBridge\AiBridgeManager::class), $handler, function (array $payload) use (&$sent) {
        $sent[] = $payload;
    });

    $task = ['phase' => 'heartbeat', 'task_id' => 'ad5d', 'tool_use_id' => 'toolu_agent', 'elapsed_seconds' => 60, 'future' => 1];
    $stats = ['spawned' => 1, 'completed' => 1];
    $handler->dispatchTask($task);
    $handler->dispatchToolResult('toolu_child', 'ok', false, 'toolu_agent');
    $handler->dispatchDone(null, ['subagent_stats' => $stats]);

    $buffered = array_map(fn (array $e) => ['event' => $e['event'], 'data' => $e['data']], $store->range('rid-sse'));

    expect($sent)->toBe($buffered)
        ->and($sent[0])->toBe(['event' => 'task', 'data' => $task])
        ->and($sent[1]['data']['parent_tool_use_id'])->toBe('toolu_agent')
        ->and($sent[2]['data']['subagent_stats'])->toBe($stats);
});

// --- Turn input: user_input, main_state, pending_inputs ---

/** A stream event as the bridge sends it. */
function turnInputWire(string $rid, string $event, array $data): StreamEvent
{
    return StreamEvent::fromArray(['type' => 'stream', 'request_id' => $rid, 'event' => $event, 'data' => $data]);
}

test('user_input and main_state are buffered in place and replay by index', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-input');
    BufferingSink::attach($handler, $store);

    $handler->dispatchEvent(turnInputWire('rid-input', 'main_state', ['state' => 'working']));
    $handler->dispatchBlockStart(BlockType::Text, 0);
    $handler->dispatchBlockDelta(BlockType::Text, 0, 'Started a helper.');
    $handler->dispatchBlockStop(BlockType::Text, 0);
    $handler->dispatchEvent(turnInputWire('rid-input', 'main_state', ['state' => 'idle']));
    $handler->dispatchEvent(turnInputWire('rid-input', 'user_input', ['message_id' => 'msg-7']));
    $handler->dispatchEvent(turnInputWire('rid-input', 'main_state', ['state' => 'working']));
    $handler->dispatchBlockStart(BlockType::Text, 1);

    $events = $store->range('rid-input');
    expect(array_column($events, 'event'))->toBe([
        'main_state', 'block_start', 'block_delta', 'block_stop', 'main_state', 'user_input', 'main_state', 'block_start',
    ])
        ->and($events[5]['data'])->toBe(['message_id' => 'msg-7'])
        ->and($events[4]['data'])->toBe(['state' => 'idle']);

    // A browser that had everything up to the idle state resumes with the
    // message being read, and then the assistant working on it.
    $resumed = $store->range('rid-input', 4);
    expect(array_column($resumed, 'event'))->toBe(['user_input', 'main_state', 'block_start'])
        ->and($resumed[0]['index'])->toBe(5);

    expect($store->status('rid-input')['status'])->toBe('streaming');
});

test('user_input and main_state are passed through whole, fields a newer bridge adds included', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-whole');
    BufferingSink::attach($handler, $store);

    $handler->dispatchEvent(turnInputWire('rid-whole', 'user_input', ['message_id' => 'm1', 'future' => true]));
    $handler->dispatchEvent(turnInputWire('rid-whole', 'main_state', ['state' => 'idle', 'future' => 2]));

    expect(array_column($store->range('rid-whole'), 'data'))
        ->toBe([['message_id' => 'm1', 'future' => true], ['state' => 'idle', 'future' => 2]]);
});

test('nothing is buffered after the turn has ended', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-late');
    BufferingSink::attach($handler, $store);

    $handler->dispatchDone(null);
    $handler->dispatchEvent(turnInputWire('rid-late', 'user_input', ['message_id' => 'm1']));
    $handler->dispatchEvent(turnInputWire('rid-late', 'main_state', ['state' => 'idle']));

    expect(array_column($store->range('rid-late'), 'event'))->toBe(['done']);
});

test('a cancelled turn tells the browser which delivered messages were never read', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-cancel');
    BufferingSink::attach($handler, $store);

    $handler->dispatchCancelled('Request was cancelled.', ['pending_inputs' => ['m2', 'm3'], 'internal' => 'x']);

    $cancelled = collect($store->range('rid-cancel'))->firstWhere('event', 'cancelled');
    expect($cancelled['data'])->toBe(['reason' => 'Request was cancelled.', 'pending_inputs' => ['m2', 'm3']])
        ->and($store->status('rid-cancel')['status'])->toBe('cancelled');
});

test('a cancelled turn with nothing pending looks as it always did', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-cancel-0');
    BufferingSink::attach($handler, $store);

    $handler->dispatchCancelled('Request was cancelled.', ['pending_inputs' => []]);

    expect($store->range('rid-cancel-0')[0]['data'])->toBe(['reason' => 'Request was cancelled.']);
});

test('a turn that ended with unread messages says so on done', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-done-pending');
    BufferingSink::attach($handler, $store);

    $handler->dispatchDone(null, ['pending_inputs' => ['m4'], 'cli_session_id' => 'sess-secret']);

    $done = collect($store->range('rid-done-pending'))->firstWhere('event', 'done');
    expect($done['data']['pending_inputs'])->toBe(['m4'])
        ->and($done['data'])->not->toHaveKey('cli_session_id');
});

test('the direct SSE path carries turn input events exactly as the buffer does', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-sse-input');
    BufferingSink::attach($handler, $store);

    $sent = [];
    $wire = new ReflectionMethod(\Tetrix\AiBridge\AiBridgeManager::class, 'wireCallbacks');
    $wire->invoke(app(\Tetrix\AiBridge\AiBridgeManager::class), $handler, function (array $payload) use (&$sent) {
        $sent[] = $payload;
    });

    $handler->dispatchEvent(turnInputWire('rid-sse-input', 'main_state', ['state' => 'idle']));
    $handler->dispatchEvent(turnInputWire('rid-sse-input', 'user_input', ['message_id' => 'msg-1']));
    $handler->dispatchCancelled('Request was cancelled.', ['pending_inputs' => ['msg-2']]);

    $buffered = array_map(fn (array $e) => ['event' => $e['event'], 'data' => $e['data']], $store->range('rid-sse-input'));

    expect($sent)->toBe($buffered)
        ->and($sent[0])->toBe(['event' => 'main_state', 'data' => ['state' => 'idle']])
        ->and($sent[1])->toBe(['event' => 'user_input', 'data' => ['message_id' => 'msg-1']])
        ->and($sent[2]['data']['pending_inputs'])->toBe(['msg-2']);
});
