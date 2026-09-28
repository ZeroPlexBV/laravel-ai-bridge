<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Facades\AiBridge;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Server\BridgeWebSocketServer;
use Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Streaming\TurnInputResult;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A message sent into a turn that is still running
|--------------------------------------------------------------------------
|
| The application holds the person's next message in a PHP-FPM worker; the
| turn runs on the operator's machine behind the serve process. So the worker
| asks the serve process (POST /api/request/input), which checks the caller
| owns the turn, sends `turn_input` down the socket, and answers once the
| bridge's `turn_input_ack` comes back — or after five seconds, with a
| `no_answer` that must never read as "the turn is over".
|
*/

/** A serve process with one connected bridge (user-1) and one running turn it owns. */
function turnInputRig(float $timeout = 5.0): object
{
    Event::fake();
    config()->set('ai-bridge.server.turn_input_timeout', $timeout);

    $rig = new stdClass();
    $rig->manager = new BridgeConnectionManager();
    $rig->messageHandler = new MessageHandler($rig->manager, app(TokenManager::class), new ToolRegistry());
    $rig->server = new BridgeWebSocketServer($rig->manager, $rig->messageHandler, app(TokenManager::class));
    $rig->loop = new StreamSelectLoop();
    (new ReflectionProperty(BridgeWebSocketServer::class, 'loop'))->setValue($rig->server, $rig->loop);

    // What reached the bridge, and how it answers: set $rig->answer to a
    // closure to have the bridge acknowledge as soon as the frame arrives.
    $rig->sent = [];
    $rig->answer = null;
    $rig->manager->setSendCallback(function (mixed $conn, array $payload) use ($rig): bool {
        $rig->sent[] = $payload;
        if ($rig->answer !== null) {
            ($rig->answer)($payload);
        }

        return true;
    });

    $rig->manager->addConnection('user-1', 'conn-1', 'socket-1');
    $rig->manager->addConnection('user-2', 'conn-2', 'socket-2');

    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start', 'cancel', 'markCompleted')->byDefault();
    $handler = new StreamHandler($provider, 'req-1');
    $handler->setMode(ProviderMode::Bridge);
    $rig->manager->registerPendingRequest('req-1', $handler, 'user-1');

    return $rig;
}

/** A bridge acknowledgement, sent from the given connection. */
function ackFrom(object $rig, string $connectionId, array $fields): void
{
    $rig->messageHandler->handleMessage($connectionId, null, json_encode($fields + [
        'type' => MessageTypes::TURN_INPUT_ACK,
        'request_id' => 'req-1',
        'message_id' => 'msg-1',
    ]));
}

/**
 * Call POST /api/request/input as the given user and collect every response written.
 *
 * @return object{responses: list<array{status: int, body: array<string, mixed>}>}
 */
function postInput(object $rig, array|string $body, string $asUser = 'user-1'): object
{
    $out = new stdClass();
    $out->responses = [];

    $tcp = Mockery::mock(ConnectionInterface::class);
    $tcp->shouldReceive('write')->andReturnUsing(function (string $raw) use ($out) {
        [$head, $json] = explode("\r\n\r\n", $raw, 2);
        preg_match('#^HTTP/1\.1 (\d+)#', $head, $m);
        $out->responses[] = ['status' => (int) $m[1], 'body' => json_decode($json, true)];

        return true;
    });
    $tcp->shouldReceive('end');

    $token = app(TokenManager::class)->generate($asUser, ['scope' => TokenManager::INTERNAL_RELAY_SCOPE], 60);
    $request = new PsrRequest('POST', '/api/request/input', ['Authorization' => "Bearer {$token}"], is_string($body) ? $body : json_encode($body));

    (new ReflectionMethod(BridgeWebSocketServer::class, 'handleHttpRequest'))->invoke($rig->server, $tcp, $request);

    return $out;
}

const INPUT_BODY = ['request_id' => 'req-1', 'message_id' => 'msg-1', 'content' => 'also check the tests'];

// --- The route ------------------------------------------------------------

it('sends turn_input to the owner\'s bridge and answers with its acceptance', function () {
    $rig = turnInputRig();
    $rig->answer = fn () => ackFrom($rig, 'conn-1', ['status' => 'accepted']);

    $out = postInput($rig, INPUT_BODY);

    expect($rig->sent)->toBe([[
        'type' => MessageTypes::TURN_INPUT,
        'request_id' => 'req-1',
        'message_id' => 'msg-1',
        'content' => 'also check the tests',
    ]])
        ->and($out->responses)->toBe([['status' => 200, 'body' => ['status' => 'accepted']]])
        ->and($rig->manager->hasPendingTurnInput('req-1', 'msg-1'))->toBeFalse();
});

it('passes a bridge refusal on with its reason', function (string $reason) {
    $rig = turnInputRig();
    $rig->answer = fn () => ackFrom($rig, 'conn-1', ['status' => 'rejected', 'reason' => $reason]);

    expect(postInput($rig, INPUT_BODY)->responses)
        ->toBe([['status' => 200, 'body' => ['status' => 'rejected', 'reason' => $reason]]]);
})->with(['turn_not_running', 'input_not_open']);

it('answers an ack that arrives later, once, and not again when the timer fires', function () {
    $rig = turnInputRig(0.2);
    $out = postInput($rig, INPUT_BODY);

    expect($out->responses)->toBe([]);

    $rig->loop->futureTick(fn () => ackFrom($rig, 'conn-1', ['status' => 'accepted']));
    $rig->loop->run();

    expect($out->responses)->toBe([['status' => 200, 'body' => ['status' => 'accepted']]]);
});

it('answers no_answer when the bridge stays silent, and forgets the message', function () {
    $rig = turnInputRig(0.05);
    $out = postInput($rig, INPUT_BODY);

    $rig->loop->run();

    expect($out->responses)->toBe([['status' => 200, 'body' => ['status' => 'rejected', 'reason' => 'no_answer']]])
        ->and($rig->manager->hasPendingTurnInput('req-1', 'msg-1'))->toBeFalse();

    // A late ack for it is harmless.
    ackFrom($rig, 'conn-1', ['status' => 'accepted']);
    expect($out->responses)->toHaveCount(1);
});

it('answers no_answer when the bridge disconnects while it waits', function () {
    $rig = turnInputRig();
    $out = postInput($rig, INPUT_BODY);

    $rig->manager->removeConnection('user-1', 'bridge_closed');

    expect($out->responses)->toBe([['status' => 200, 'body' => ['status' => 'rejected', 'reason' => 'no_answer']]]);
});

it('refuses a caller who does not own the turn, without telling the bridge', function () {
    $rig = turnInputRig();

    $out = postInput($rig, INPUT_BODY, asUser: 'user-2');

    expect($out->responses)->toBe([['status' => 403, 'body' => ['status' => 'rejected', 'reason' => 'not_owner']]])
        ->and($rig->sent)->toBe([])
        ->and($rig->manager->hasPendingTurnInput('req-1', 'msg-1'))->toBeFalse();
});

it('does not let another user\'s bridge answer for the owner\'s message', function () {
    $rig = turnInputRig(0.05);
    $rig->answer = fn () => ackFrom($rig, 'conn-2', ['status' => 'rejected', 'reason' => 'turn_not_running']);

    $out = postInput($rig, INPUT_BODY);
    $rig->loop->run();

    // Only the timeout answered: user-2's frame could have sent the message
    // round as a new turn, which is exactly what it must not be able to do.
    expect($out->responses)->toBe([['status' => 200, 'body' => ['status' => 'rejected', 'reason' => 'no_answer']]]);
});

it('says turn_not_running for a turn this process is not running, without asking the bridge', function () {
    $rig = turnInputRig();

    $out = postInput($rig, ['request_id' => 'req-over'] + INPUT_BODY);

    expect($out->responses)->toBe([['status' => 200, 'body' => ['status' => 'rejected', 'reason' => 'turn_not_running']]])
        ->and($rig->sent)->toBe([]);
});

it('refuses the same message while it is still waiting, so it is never written twice', function () {
    $rig = turnInputRig();
    postInput($rig, INPUT_BODY);

    $second = postInput($rig, INPUT_BODY);

    expect($second->responses)->toBe([['status' => 200, 'body' => ['status' => 'rejected', 'reason' => 'duplicate']]])
        ->and($rig->sent)->toHaveCount(1);
});

it('says send_failed when the frame cannot be written to the bridge', function () {
    $rig = turnInputRig();
    $rig->manager->setSendCallback(fn () => false);

    expect(postInput($rig, INPUT_BODY)->responses)
        ->toBe([['status' => 200, 'body' => ['status' => 'rejected', 'reason' => 'send_failed']]])
        ->and($rig->manager->hasPendingTurnInput('req-1', 'msg-1'))->toBeFalse();
});

it('passes a list of content blocks through untouched', function () {
    $rig = turnInputRig();
    $rig->answer = fn () => ackFrom($rig, 'conn-1', ['status' => 'accepted']);
    $blocks = [['type' => 'text', 'text' => 'also check the tests']];

    postInput($rig, ['content' => $blocks] + INPUT_BODY);

    expect($rig->sent[0]['content'])->toBe($blocks);
});

it('rejects a malformed body with 400 rather than throwing past the loop', function (array|string $body) {
    $rig = turnInputRig();

    $out = postInput($rig, $body);

    expect($out->responses[0]['status'])->toBe(400)
        ->and($rig->sent)->toBe([]);
})->with([
    'not JSON' => ['{nope'],
    'no request_id' => [['message_id' => 'msg-1', 'content' => 'x']],
    'a numeric request_id' => [['request_id' => 7, 'message_id' => 'msg-1', 'content' => 'x']],
    'an array message_id' => [['request_id' => 'req-1', 'message_id' => ['m'], 'content' => 'x']],
    'empty content' => [['request_id' => 'req-1', 'message_id' => 'msg-1', 'content' => '']],
    'content as an object' => [['request_id' => 'req-1', 'message_id' => 'msg-1', 'content' => ['text' => 'x']]],
    'numeric content' => [['request_id' => 'req-1', 'message_id' => 'msg-1', 'content' => 5]],
]);

it('refuses a user-facing bridge token', function () {
    $rig = turnInputRig();
    $out = new stdClass();
    $out->status = null;
    $tcp = Mockery::mock(ConnectionInterface::class);
    $tcp->shouldReceive('write')->andReturnUsing(function (string $raw) use ($out) {
        preg_match('#^HTTP/1\.1 (\d+)#', $raw, $m);
        $out->status = (int) $m[1];

        return true;
    });
    $tcp->shouldReceive('end');

    $token = app(TokenManager::class)->generate('user-1');
    (new ReflectionMethod(BridgeWebSocketServer::class, 'handleHttpRequest'))->invoke(
        $rig->server,
        $tcp,
        new PsrRequest('POST', '/api/request/input', ['Authorization' => "Bearer {$token}"], json_encode(INPUT_BODY)),
    );

    expect($out->status)->toBe(401)->and($rig->sent)->toBe([]);
});

// --- The public entry points ------------------------------------------------

/** A bridge conversation on a managed connection, with a running turn buffered for it. */
function runningConversationTurn(string $rid = 'req-1'): Conversation
{
    $store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $store);

    $connection = Connection::create(['type' => 'bridge', 'name' => 'laptop', 'connection_key' => 'key-1']);
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude', 'connection_id' => $connection->id]);
    $store->start($rid, ['conversation_id' => (string) $conversation->id]);

    return $conversation;
}

it('sendTurnInput reaches the serve process as the user the turn was routed to', function () {
    runningConversationTurn();
    Http::fake(['*/api/request/input' => Http::response(['status' => 'accepted'])]);

    $result = AiBridge::sendTurnInput('req-1', 'msg-1', 'also check the tests');

    expect($result)->toBeInstanceOf(TurnInputResult::class)
        ->and($result->isAccepted())->toBeTrue()
        ->and($result->toArray())->toBe(['status' => 'accepted']);

    Http::assertSent(function (HttpRequest $request) {
        $token = substr($request->header('Authorization')[0], 7);
        $claims = app(TokenManager::class)->validate($token, TokenManager::INTERNAL_RELAY_SCOPE);

        // The managed connection's key, not whoever is logged in: that is the
        // identity the bridge connected as, and so the turn's owner.
        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/request/input')
            && $claims->sub === 'key-1'
            && $request->data() === ['request_id' => 'req-1', 'message_id' => 'msg-1', 'content' => 'also check the tests'];
    });
});

it('sendTurnInput takes an explicit user over the one it would resolve', function () {
    runningConversationTurn();
    Http::fake(['*/api/request/input' => Http::response(['status' => 'accepted'])]);

    AiBridge::sendTurnInput('req-1', 'msg-1', [['type' => 'text', 'text' => 'hi']], 'user-9');

    Http::assertSent(function (HttpRequest $request) {
        $claims = app(TokenManager::class)->validate(substr($request->header('Authorization')[0], 7), TokenManager::INTERNAL_RELAY_SCOPE);

        return $claims->sub === 'user-9' && $request->data()['content'] === [['type' => 'text', 'text' => 'hi']];
    });
});

it('sendTurnInput reports each answer the serve process gives', function (mixed $response, array $expected) {
    runningConversationTurn();
    Http::fake(['*/api/request/input' => $response]);

    expect(AiBridge::sendTurnInput('req-1', 'msg-1', 'hi')->toArray())->toBe($expected);
})->with([
    'turn not running' => [fn () => Http::response(['status' => 'rejected', 'reason' => 'turn_not_running']), ['status' => 'rejected', 'reason' => 'turn_not_running']],
    'no answer' => [fn () => Http::response(['status' => 'rejected', 'reason' => 'no_answer']), ['status' => 'rejected', 'reason' => 'no_answer']],
    'a refusal without a reason' => [fn () => Http::response(['status' => 'rejected']), ['status' => 'rejected']],
    'not the owner' => [fn () => Http::response(['status' => 'rejected', 'reason' => 'not_owner'], 403), ['status' => 'rejected', 'reason' => 'not_owner']],
    'a malformed request' => [fn () => Http::response(['error' => 'invalid_request'], 400), ['status' => 'rejected', 'reason' => 'invalid_request']],
    'a broken serve process' => [fn () => Http::response('boom', 500), ['status' => 'rejected', 'reason' => 'unreachable']],
    // Never "accepted" by accident: only the exact word is acceptance.
    'an unexpected body' => [fn () => Http::response(['ok' => true]), ['status' => 'rejected']],
]);

it('sendTurnInput reports a serve process it cannot reach as unreachable, not as a finished turn', function () {
    runningConversationTurn();
    Http::fake(fn () => throw new ConnectionException('refused'));

    expect(AiBridge::sendTurnInput('req-1', 'msg-1', 'hi')->toArray())
        ->toBe(['status' => 'rejected', 'reason' => 'unreachable']);
});

it('sendTurnInput refuses empty content before sending anything', function (string|array $content) {
    Http::fake();

    expect(fn () => AiBridge::sendTurnInput('req-1', 'msg-1', $content, 'user-1'))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with(['', [[]]]);

it('sendTurnInput refuses when no user can be resolved', function () {
    app()->instance(StreamStoreContract::class, new ArrayStreamStore());
    Http::fake();

    expect(fn () => AiBridge::sendTurnInput('req-unknown', 'msg-1', 'hi'))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('inputOpen follows the stream metadata the ack wrote, while the turn runs', function () {
    runningConversationTurn();
    $store = app(StreamStoreContract::class);

    expect(AiBridge::inputOpen('req-1'))->toBeFalse();

    $store->mergeMetadata('req-1', ['input_open' => true]);
    expect(AiBridge::inputOpen('req-1'))->toBeTrue();

    $store->complete('req-1', 'completed');
    expect(AiBridge::inputOpen('req-1'))->toBeFalse();
});

it('the facade resolves to the manager that implements both', function () {
    expect(AiBridge::getFacadeRoot())->toBeInstanceOf(AiBridgeManager::class)
        ->and(method_exists(AiBridgeManager::class, 'sendTurnInput'))->toBeTrue()
        ->and(method_exists(AiBridgeManager::class, 'inputOpen'))->toBeTrue();
});
