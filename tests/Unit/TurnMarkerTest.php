<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Streaming\BufferingSink;
use Tetrix\AiBridge\Streaming\ConversationRecorder;
use Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Streaming\TurnMarker;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The conversation's "a turn is running" bookmark ends with its turn
|--------------------------------------------------------------------------
|
| `streaming_request_id` was cleared only by the recorder, which only hears
| endings that reach a handler in this process. A serve process that restarted
| mid-turn left it for ever (three on the dev primary, one from 18 Sep).
|
*/

/** A serve process with user-1's bridge connected, and a conversation routed to user-1. */
function markerRig(string $rid = 'req-1', string $key = 'user-1'): object
{
    Event::fake();
    $rig = new stdClass();
    $rig->store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $rig->store);
    $rig->manager = new BridgeConnectionManager();
    $rig->sent = [];
    $rig->manager->setSendCallback(function (mixed $conn, array $payload) use ($rig): bool {
        $rig->sent[] = $payload;

        return true;
    });
    $rig->handler = new MessageHandler($rig->manager, app(TokenManager::class), new ToolRegistry());
    $rig->manager->addConnection('user-1', 'conn-1', 'socket-1');
    $rig->manager->addConnection('user-2', 'conn-2', 'socket-2');

    $connection = Connection::create(['type' => 'bridge', 'name' => 'laptop', 'connection_key' => $key]);
    $rig->conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude', 'connection_id' => $connection->id]);
    $rig->conversation->forceFill(['streaming_request_id' => $rid])->save();
    $rig->store->start($rid, ['conversation_id' => (string) $rig->conversation->id]);

    return $rig;
}

function markerOf(Conversation $conversation): ?string
{
    return Conversation::query()->whereKey($conversation->id)->value('streaming_request_id');
}

/** A turn this process relays, recorded and buffered as RelayStream wires it. */
function relayedTurn(object $rig, string $rid = 'req-1'): StreamHandler
{
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start', 'cancel', 'markCompleted')->byDefault();
    $handler = new StreamHandler($provider, $rid);
    $handler->setMode(ProviderMode::Bridge);
    BufferingSink::attach($handler, $rig->store);
    ConversationRecorder::attach($handler, $rig->conversation);
    $rig->manager->registerPendingRequest($rid, $handler, 'user-1');

    return $handler;
}

it('ends a turn a restarted serve process never knew, when its own bridge says it is done', function () {
    $rig = markerRig();

    $rig->handler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::DONE, 'data' => ['usage' => null],
    ]));

    $events = $rig->store->range('req-1');
    expect(markerOf($rig->conversation))->toBeNull()
        ->and($rig->store->status('req-1')['status'])->toBe('failed')
        ->and(end($events)['event'] ?? null)->toBe(MessageTypes::ERROR)
        ->and(end($events)['data']['code'] ?? null)->toBe(TurnMarker::LOST_CODE);
});

it('ends such a turn on a top-level error and on a cancelled too', function (array $frame) {
    $rig = markerRig();

    $rig->handler->handleMessage('conn-1', null, json_encode($frame + ['request_id' => 'req-1']));

    expect(markerOf($rig->conversation))->toBeNull()
        ->and($rig->store->status('req-1')['status'])->toBe('failed');
})->with([
    'error' => [['type' => MessageTypes::ERROR, 'code' => 'timeout', 'message' => 'x']],
    'cancelled' => [['type' => MessageTypes::CANCELLED]],
    'error in a stream envelope' => [['type' => MessageTypes::STREAM, 'event' => MessageTypes::ERROR, 'data' => ['code' => 'x', 'message' => 'y']]],
]);

it('does not let another user\'s bridge end the turn', function () {
    $rig = markerRig();

    $rig->handler->handleMessage('conn-2', null, json_encode([
        'type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::DONE, 'data' => [],
    ]));

    expect(markerOf($rig->conversation))->toBe('req-1')
        ->and($rig->store->status('req-1')['status'])->toBe('streaming');
});

it('does not treat session_lost for an unknown turn as its end', function () {
    $rig = markerRig();

    $rig->handler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::ERROR, 'data' => ['code' => 'session_lost', 'message' => 'x'],
    ]));

    expect(markerOf($rig->conversation))->toBe('req-1');
});

it('leaves the next turn\'s bookmark alone when a stop is answered after it started', function () {
    $rig = markerRig();
    $rig->conversation->forceFill(['streaming_request_id' => 'req-2'])->save();

    $rig->handler->handleMessage('conn-1', null, json_encode(['type' => MessageTypes::CANCELLED, 'request_id' => 'req-1']));

    expect(markerOf($rig->conversation))->toBe('req-2');
});

it('the recorder clears only the bookmark of its own turn', function () {
    $rig = markerRig();
    $handler = relayedTurn($rig);
    $rig->conversation->forceFill(['streaming_request_id' => 'req-2'])->save();

    $handler->dispatchCancelled('stop');

    expect(markerOf($rig->conversation))->toBe('req-2');
});

it('the recorder still clears its own turn on every ending', function (string $ending) {
    $rig = markerRig();
    $handler = relayedTurn($rig);

    match ($ending) {
        'done' => $handler->dispatchDone(null),
        'error' => $handler->dispatchError('x', 'y'),
        'cancelled' => $handler->dispatchCancelled('stop'),
    };

    expect(markerOf($rig->conversation))->toBeNull();
})->with(['done', 'error', 'cancelled']);

it('ends every running turn when the serve process stops, and tells the machine to stop it', function () {
    $rig = markerRig();
    relayedTurn($rig);

    expect($rig->manager->failAllPendingRequests())->toBe(1);

    $events = $rig->store->range('req-1');
    expect(markerOf($rig->conversation))->toBeNull()
        ->and($rig->store->status('req-1')['status'])->toBe('failed')
        ->and(end($events)['data']['code'] ?? null)->toBe('server_restarting')
        ->and($rig->manager->getPendingRequest('req-1'))->toBeNull()
        ->and($rig->sent)->toContain(['type' => MessageTypes::CANCEL, 'request_id' => 'req-1']);
});

it('the sweep clears bookmarks of ended turns and of old ones with no buffer, and nothing else', function () {
    $rig = markerRig('ended');
    $rig->store->complete('ended', 'completed');

    $make = function (string $rid, ?string $bufferStatus, int $ageSeconds) use ($rig): Conversation {
        $c = Conversation::create(['mode' => 'bridge', 'provider' => 'claude', 'connection_id' => $rig->conversation->connection_id]);
        $c->forceFill(['streaming_request_id' => $rid])->save();
        Conversation::query()->whereKey($c->id)->update(['updated_at' => now()->subSeconds($ageSeconds)]);
        if ($bufferStatus !== null) {
            $rig->store->start($rid, []);
            if ($bufferStatus !== 'streaming') {
                $rig->store->complete($rid, $bufferStatus);
            }
        }

        return $c;
    };

    $failed = $make('failed-one', 'failed', 5);
    $running = $make('running', 'streaming', 99999);
    $oldGone = $make('expired', null, 3600);
    $claim = $make('claim_starting', null, 30);

    $cleared = TurnMarker::sweep(600);

    expect($cleared)->toEqualCanonicalizing(['ended', 'failed-one', 'expired'])
        ->and(markerOf($rig->conversation))->toBeNull()
        ->and(markerOf($failed))->toBeNull()
        ->and(markerOf($oldGone))->toBeNull()
        ->and(markerOf($running))->toBe('running')
        ->and(markerOf($claim))->toBe('claim_starting');
});

it('the sweep command reports what it cleared', function () {
    $rig = markerRig('ended');
    $rig->store->complete('ended', 'cancelled');

    $this->artisan('ai-bridge:sweep-turn-markers')
        ->expectsOutputToContain('1 stale turn bookmark(s) cleared.')
        ->assertSuccessful();

    expect(markerOf($rig->conversation))->toBeNull();
});
