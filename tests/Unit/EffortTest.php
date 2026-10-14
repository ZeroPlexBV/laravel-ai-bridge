<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Connections\ConnectionStatus;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Http\Controllers\ConversationController;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Models\Message;
use Tetrix\AiBridge\Protocol\AiRequestPayload;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Streaming\BridgeStream;
use Tetrix\AiBridge\Streaming\RelayStream;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/*
|--------------------------------------------------------------------------
| The thinking level: `options.effort` and `hello.effort_levels`
|--------------------------------------------------------------------------
|
| A turn's `effort` option goes to the bridge as `ai_request.options.effort`,
| beside `options.model`, on both send paths and on a `session_lost` re-issue.
| Absent, nothing is sent. The bridge announces which levels it honours as
| `hello.effort_levels`, which ConnectionStatus::for() reports.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
});

const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

function effortStream(array $options, ?BridgeConnectionManager $manager = null): BridgeStream
{
    $stream = new BridgeStream(
        $manager ?? new BridgeConnectionManager(),
        new ToolRegistry(),
        app(TokenManager::class),
        'user-1',
    );
    $stream->setConversationId('conv-1');
    $stream->setProvider('claude');
    $stream->setMessage('hello');
    $stream->setOptions($options);

    return $stream;
}

/** The ai_request body the relay path posts, as the serve process maps it back. */
function effortRelayedPayload(array $options): array
{
    Http::fake(['*/api/request' => Http::response(['ok' => true], 200)]);

    effortStream($options)->start();

    $body = [];
    Http::assertSent(function ($request) use (&$body) {
        $body = $request->data();

        return true;
    });

    return AiRequestPayload::fromRelayBody($body, $body['request_id'] ?? '');
}

/** A turn's StreamHandler bound to a (numeric) conversation id, with no provider behind it. */
function effortHandler(string $conversationId): StreamHandler
{
    $handler = new StreamHandler(new class implements \Tetrix\AiBridge\Contracts\StreamableProvider
    {
        public function setConversationId(string $c): static { return $this; }
        public function setMessage(string $m): static { return $this; }
        public function setOptions(array $o): static { return $this; }
        public function start(): void {}
        public function cancel(): void {}
        public function getStreamHandler(): StreamHandler { throw new RuntimeException('n/a'); }
        public function markCompleted(): void {}
    });
    $handler->setMode(ProviderMode::Bridge);
    $handler->setConversationId($conversationId);

    return $handler;
}

function sessionLostFrame(string $requestId): string
{
    return json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => $requestId,
        'event' => MessageTypes::ERROR,
        'data' => ['code' => 'session_lost', 'message' => 'cannot resume'],
    ]);
}

// ── options.effort ────────────────────────────────────────────────────

test('effort is sent as options.effort beside the model', function () {
    $payload = effortStream(['model' => 'opus', 'effort' => 'high'])->buildRequestBody();

    expect($payload['options'])->toBe(['model' => 'opus', 'effort' => 'high']);
});

test('effort survives the PHP-FPM relay unchanged', function () {
    $direct = effortStream(['model' => 'opus', 'effort' => 'xhigh'])->buildRequestBody();
    $relayed = effortRelayedPayload(['model' => 'opus', 'effort' => 'xhigh']);

    expect($relayed['options']['effort'])->toBe('xhigh')
        ->and($relayed['options'])->toBe($direct['options']);
});

test('without effort nothing is sent and the payload is what it was', function () {
    $payload = effortStream(['model' => 'opus'])->buildRequestBody();
    $bare = effortStream([])->buildRequestBody();

    expect($payload['options'])->toBe(['model' => 'opus'])
        ->and($bare)->not->toHaveKey('options')
        ->and(effortRelayedPayload([]))->not->toHaveKey('options');
});

test('an empty, wrong-typed or oversized effort is not sent', function (mixed $effort) {
    $payload = effortStream(['model' => 'opus', 'effort' => $effort])->buildRequestBody();

    expect($payload['options'])->toBe(['model' => 'opus']);
})->with([
    'empty' => [''],
    'array' => [['high']],
    'int' => [3],
    'bool' => [true],
    'too long' => [str_repeat('x', AiRequestPayload::MAX_EFFORT_LENGTH + 1)],
]);

test('a conversation turn forwards effort through streamConversation', function () {
    Http::fake(['*/api/request' => Http::response(['ok' => true], 200)]);

    $connection = Connection::create(['type' => Connection::TYPE_BRIDGE, 'name' => 'box', 'connection_key' => 'key-1']);
    $conversation = Conversation::create([
        'mode' => 'bridge', 'provider' => 'claude', 'model' => 'opus', 'connection_id' => $connection->id,
    ]);

    app(AiBridgeManager::class)->streamConversation($conversation, 'hi', ['effort' => 'max'])->start();

    Http::assertSent(fn ($request) => ($request->data()['options'] ?? null) === ['model' => 'opus', 'effort' => 'max']);
});

test('the conversation route takes a per-turn effort and refuses a malformed one', function () {
    app(AiBridgeManager::class)->resolveConversationsUsing(fn ($request) => Conversation::query());
    app(AiBridgeManager::class)->resolveConnectionsUsing(fn ($request) => Connection::query());
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);

    $manager = Mockery::mock(AiBridgeManager::class, [
        app(ToolRegistry::class),
        app(BridgeConnectionManager::class),
        app(TokenManager::class),
    ])->makePartial();
    $manager->shouldReceive('conversationsQuery')->andReturn(Conversation::whereKey($conversation->id));
    $manager->shouldReceive('startConversationStream')->once()
        ->withArgs(fn ($c, $message, $options) => ($options['effort'] ?? null) === 'medium')
        ->andReturn('rid-1');
    app()->instance(AiBridgeManager::class, $manager);
    $controller = new ConversationController($manager, app(ToolRegistry::class));

    $ok = $controller->stream(Request::create('/x', 'POST', ['message' => 'go', 'effort' => 'medium']), $conversation->id);
    $bad = $controller->stream(Request::create('/x', 'POST', ['message' => 'go', 'effort' => ['high']]), $conversation->id);

    expect($ok->getStatusCode())->toBe(200)
        ->and($bad->getStatusCode())->toBe(422);
});

// ── re-issue after session_lost ───────────────────────────────────────

test('a relayed turn re-issued after session_lost keeps its effort', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude', 'model' => 'opus', 'cli_session_id' => 'dead']);
    $conversation->appendMessage(Message::ROLE_USER, 'I enter the cave');

    $sent = [];
    $manager = new BridgeConnectionManager();
    $manager->setSendCallback(function ($connection, $payload) use (&$sent) {
        $sent[] = $payload;

        return true;
    });
    $manager->addConnection('user-1', 'conn-1');

    // The carry the serve process records for a relayed turn, from the payload it sent.
    $original = AiRequestPayload::build([
        'request_id' => 'req-1', 'message' => 'I enter the cave',
        'options' => ['model' => 'opus', 'effort' => 'high'],
    ]);
    expect(AiRequestPayload::reissueCarry($original))->toBe(['model' => 'opus', 'effort' => 'high']);

    $messages = new MessageHandler($manager, app(TokenManager::class), new ToolRegistry());
    $messages->registerRelayedRequest('req-1', 'user-1', (string) $conversation->id, AiRequestPayload::reissueCarry($original));
    $messages->handleMessage('conn-1', null, sessionLostFrame('req-1'));

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['cli_session_id'])->toBeNull()
        ->and($sent[0]['options'])->toBe(['model' => 'opus', 'effort' => 'high']);
});

test('a turn sent in-process re-issued after session_lost keeps its effort', function () {
    Event::fake();
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude', 'cli_session_id' => 'dead']);
    $conversation->appendMessage(Message::ROLE_USER, 'I enter the cave');

    $sent = [];
    $manager = new BridgeConnectionManager();
    $manager->setSendCallback(function ($connection, $payload) use (&$sent) {
        $sent[] = $payload;

        return true;
    });
    $manager->addConnection('user-1', 'conn-1');

    // The direct path: BridgeStream sends over the in-memory connection and
    // records its carry with the pending request.
    $stream = effortStream(['effort' => 'low', 'cli_session_id' => 'dead'], $manager);
    $stream->setConversationId((string) $conversation->id);
    // As AiBridgeManager::buildStream() does: the handler names the conversation too.
    $stream->getStreamHandler()->setConversationId((string) $conversation->id);
    $stream->start();
    $requestId = $stream->getStreamHandler()->requestId;

    $messages = new MessageHandler($manager, app(TokenManager::class), new ToolRegistry());
    $messages->handleMessage('conn-1', null, sessionLostFrame($requestId));

    expect($sent)->toHaveCount(2)
        ->and($sent[0]['options']['effort'])->toBe('low')
        ->and($sent[1]['request_id'])->toBe($requestId)
        ->and($sent[1]['cli_session_id'])->toBeNull()
        ->and($sent[1]['options']['effort'])->toBe('low');
});

test('a re-issued turn that had no effort sends none', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude', 'cli_session_id' => 'dead']);
    $conversation->appendMessage(Message::ROLE_USER, 'I enter the cave');

    $sent = [];
    $manager = new BridgeConnectionManager();
    $manager->setSendCallback(function ($connection, $payload) use (&$sent) {
        $sent[] = $payload;

        return true;
    });
    $manager->addConnection('user-1', 'conn-1');
    $manager->registerPendingRequest('req-1', effortHandler((string) $conversation->id), 'user-1');

    (new MessageHandler($manager, app(TokenManager::class), new ToolRegistry()))
        ->handleMessage('conn-1', null, sessionLostFrame('req-1'));

    expect($sent)->toHaveCount(1)
        ->and($sent[0])->not->toHaveKey('options');
});

// ── a per-turn model beats the conversation's stored one ─────────────

function modelRig(?string $storedModel): Conversation
{
    Http::fake(['*/api/request' => Http::response(['ok' => true], 200)]);
    $connection = Connection::create(['type' => Connection::TYPE_BRIDGE, 'name' => 'box', 'connection_key' => 'key-1']);

    return Conversation::create([
        'mode' => 'bridge', 'provider' => 'claude', 'model' => $storedModel, 'connection_id' => $connection->id,
    ]);
}

test('a per-turn model wins over the conversation\'s stored model, and does not change it', function () {
    $conversation = modelRig('sonnet');

    app(AiBridgeManager::class)->streamConversation($conversation, 'hi', ['model' => 'opus', 'effort' => 'high'])->start();

    Http::assertSent(fn ($request) => ($request->data()['options'] ?? null) === ['model' => 'opus', 'effort' => 'high']);
    expect($conversation->fresh()->model)->toBe('sonnet');
});

test('without a per-turn model the stored model is sent, as before', function (mixed $turnModel) {
    $conversation = modelRig('sonnet');
    $options = $turnModel === 'absent' ? [] : ['model' => $turnModel];

    app(AiBridgeManager::class)->streamConversation($conversation, 'hi', $options)->start();

    Http::assertSent(fn ($request) => ($request->data()['options'] ?? null) === ['model' => 'sonnet']);
})->with(['absent' => ['absent'], 'null' => [null], 'empty' => ['']]);

test('with no model anywhere none is sent', function () {
    $conversation = modelRig(null);

    app(AiBridgeManager::class)->streamConversation($conversation, 'hi')->start();

    Http::assertSent(fn ($request) => ! isset($request->data()['options']['model']));
});

test('the buffered turn\'s metadata names the per-turn model', function () {
    $store = new \Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore();
    app()->instance(\Tetrix\AiBridge\Contracts\StreamStoreContract::class, $store);
    $conversation = modelRig('sonnet');

    $requestId = app(AiBridgeManager::class)->startConversationStream($conversation, 'hi', ['model' => 'opus']);

    expect($store->status($requestId)['metadata']['model'])->toBe('opus');
});

test('the assistant row of a relayed turn records the model the turn was sent with', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude', 'model' => 'sonnet']);
    $conversation->appendMessage(Message::ROLE_USER, 'hi');

    $relay = new RelayStream('req-m', (string) $conversation->id, 'opus');
    $handler = $relay->getStreamHandler();
    $handler->dispatchBlockStart(BlockType::Text, 0);
    $handler->dispatchBlockDelta(BlockType::Text, 0, 'Hello');
    $handler->dispatchBlockStop(BlockType::Text, 0);
    $handler->dispatchDone();

    expect($conversation->messages()->where('role', 'assistant')->first()->model)->toBe('opus');
});

test('a re-issue after session_lost keeps the per-turn model rather than reverting to the stored one', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude', 'model' => 'sonnet', 'cli_session_id' => 'dead']);
    $conversation->appendMessage(Message::ROLE_USER, 'hi');

    $sent = [];
    $manager = new BridgeConnectionManager();
    $manager->setSendCallback(function ($connection, $payload) use (&$sent) {
        $sent[] = $payload;

        return true;
    });
    $manager->addConnection('user-1', 'conn-1');

    $messages = new MessageHandler($manager, app(TokenManager::class), new ToolRegistry());
    $messages->registerRelayedRequest('req-1', 'user-1', (string) $conversation->id, ['model' => 'opus']);
    $messages->handleMessage('conn-1', null, sessionLostFrame('req-1'));

    expect($sent[0]['options'])->toBe(['model' => 'opus']);
});

// ── hello.effort_levels ───────────────────────────────────────────────

test('hello effort_levels is recorded with the bridge description', function () {
    Event::fake();
    $manager = new BridgeConnectionManager();
    $manager->addConnection('user-1', 'conn-1');
    $handler = new MessageHandler($manager, app(TokenManager::class), new ToolRegistry());

    $handler->handleMessage('conn-1', null, json_encode([
        'type' => 'hello', 'version' => '0.1', 'bridge_version' => '0.26.0', 'providers' => [],
        'effort_levels' => EFFORT_LEVELS,
    ]));

    expect($manager->getBridgeInfo('user-1')['effort_levels'])->toBe(EFFORT_LEVELS);
});

test('a hello without usable effort_levels records an empty list', function (mixed $raw) {
    $message = ['type' => 'hello', 'providers' => []];
    if ($raw !== 'absent') {
        $message['effort_levels'] = $raw;
    }

    expect(MessageHandler::bridgeInfoFromHello($message)['effort_levels'])->toBe([]);
})->with([
    'absent (an older bridge)' => ['absent'],
    'a string' => ['high'],
    'an object' => [['a' => 'high']],
    'only junk entries' => [[1, '', null, ['high']]],
]);

test('hello effort_levels keeps the usable entries, once each', function () {
    $info = MessageHandler::bridgeInfoFromHello([
        'type' => 'hello', 'providers' => [],
        'effort_levels' => ['low', 7, 'high', '', 'low', str_repeat('x', 40)],
    ]);

    expect($info['effort_levels'])->toBe(['low', 'high']);
});

test('ConnectionStatus::for() reports effort_levels live, and remembers them while the machine is off', function () {
    Http::fake(['*/api/status' => Http::sequence()
        ->push([
            'connected' => true,
            'connected_at' => now()->timestamp,
            'providers' => [],
            'bridge' => MessageHandler::bridgeInfoFromHello([
                'type' => 'hello', 'bridge_version' => '0.26.0', 'providers' => [], 'effort_levels' => EFFORT_LEVELS,
            ]),
        ])
        ->push(['connected' => false])]);

    $connection = Connection::create(['type' => Connection::TYPE_BRIDGE, 'name' => 'box', 'connection_key' => 'key-1']);
    $status = app(ConnectionStatus::class);

    $live = $status->for($connection);
    expect($live['connected'])->toBeTrue()
        ->and($live['effort_levels'])->toBe(EFFORT_LEVELS);

    $offline = $status->for($connection->fresh());
    expect($offline['connected'])->toBeFalse()
        ->and($offline['effort_levels'])->toBe(EFFORT_LEVELS);
});

test('ConnectionStatus::for() reports no effort_levels for an older bridge or BYOK', function () {
    Http::fake(['*/api/status' => Http::response([
        'connected' => true,
        'providers' => [],
        'bridge' => MessageHandler::bridgeInfoFromHello(['type' => 'hello', 'bridge_version' => '0.21.0', 'providers' => []]),
    ])]);

    $bridge = Connection::create(['type' => Connection::TYPE_BRIDGE, 'name' => 'old', 'connection_key' => 'key-2']);
    $byok = Connection::create(['type' => Connection::TYPE_BYOK, 'name' => 'k']);

    expect(app(ConnectionStatus::class)->for($bridge)['effort_levels'])->toBe([])
        ->and(app(ConnectionStatus::class)->effortLevels($bridge))->toBe([])
        ->and(app(ConnectionStatus::class)->for($byok)['effort_levels'])->toBe([]);
});

test('a stored bridge record from before effort_levels reads as none', function () {
    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE, 'name' => 'box',
        'last_bridge' => ['bridge_version' => '0.21.0', 'capabilities' => ['turn_input']],
    ]);

    expect(app(ConnectionStatus::class)->for($connection)['effort_levels'])->toBe([]);
});
