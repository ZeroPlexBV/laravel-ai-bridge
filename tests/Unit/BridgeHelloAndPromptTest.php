<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Connections\ConnectionStatus;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Events\TurnInputsReturned;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Protocol\AiRequestPayload;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Streaming\BufferingSink;
use Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| What a bridge says about itself, the bridge prompt, and the late
| account of unread messages after a dropped connection.
|--------------------------------------------------------------------------
*/

const HELLO_021 = [
    'type' => 'hello',
    'version' => '0.1',
    'bridge_version' => '0.21.0',
    'providers' => [],
    'turn_input' => true,
    'file_uploads' => true,
    'file_downloads' => true,
    'app_backends' => true,
    'attachment_limits' => ['max_file_bytes' => 52428800, 'max_total_bytes' => 104857600, 'max_count' => 10],
];

function helloRig(): object
{
    Event::fake();
    $rig = new stdClass();
    $rig->manager = new BridgeConnectionManager();
    $rig->handler = new MessageHandler($rig->manager, app(TokenManager::class), new ToolRegistry());

    return $rig;
}

it('records bridge_version, attachment_limits and capabilities from a pre-authenticated hello', function () {
    $rig = helloRig();
    $rig->manager->addConnection('user-1', 'conn-1');

    $reply = $rig->handler->handleMessage('conn-1', null, json_encode(HELLO_021));

    expect($reply['type'])->toBe(MessageTypes::WELCOME)
        ->and($rig->manager->getBridgeInfo('user-1'))->toBe([
            'bridge_version' => '0.21.0',
            'protocol_version' => '0.1',
            'attachment_limits' => ['max_file_bytes' => 52428800, 'max_total_bytes' => 104857600, 'max_count' => 10],
            'capabilities' => ['turn_input', 'file_uploads', 'file_downloads', 'app_backends'],
        ])
        ->and($rig->manager->bridgeSupports('user-1', 'file_uploads'))->toBeTrue();
});

it('records the same from a hello that authenticates with a token in its body', function () {
    $rig = helloRig();
    $token = app(TokenManager::class)->generate('user-9');

    $rig->handler->handleMessage('conn-9', null, json_encode(HELLO_021 + ['token' => $token]));

    expect($rig->manager->getBridgeInfo('user-9')['bridge_version'])->toBe('0.21.0');
});

it('reads an old bridge hello as no capabilities, and drops fields of the wrong type', function () {
    $rig = helloRig();
    $rig->manager->addConnection('user-1', 'conn-1');

    $rig->handler->handleMessage('conn-1', null, json_encode([
        'type' => 'hello',
        'version' => 1.0,               // not a string: must not reach ltrim() under strict_types
        'bridge_version' => ['0.15.0'],
        'turn_input' => 'yes',
        'attachment_limits' => ['max_file_bytes' => '50', 'max_count' => 3],
        'providers' => [],
    ]));

    expect($rig->manager->getBridgeInfo('user-1'))->toBe([
        'bridge_version' => null,
        'protocol_version' => null,
        'attachment_limits' => ['max_count' => 3],
        'capabilities' => [],
    ]);
});

it('exposes the bridge description through ConnectionStatus and caches it for when the machine is off', function () {
    Http::fake(['*/api/status' => Http::sequence()
        ->push([
            'connected' => true,
            'connected_at' => now()->timestamp,
            'providers' => [],
            'bridge' => MessageHandler::bridgeInfoFromHello(HELLO_021),
        ])
        ->push(['connected' => false])]);

    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'name' => 'box',
        'connection_key' => 'key-1',
    ]);

    $status = app(ConnectionStatus::class);
    $live = $status->for($connection);

    expect($live['bridge_version'])->toBe('0.21.0')
        ->and($live['attachment_limits']['max_file_bytes'])->toBe(52428800)
        ->and($live['capabilities'])->toContain('file_downloads')
        ->and($connection->fresh()->last_bridge['bridge_version'])->toBe('0.21.0');

    // Switched off: still says what it ran last time.
    $offline = $status->for($connection->fresh());
    expect($offline['connected'])->toBeFalse()
        ->and($offline['bridge_version'])->toBe('0.21.0')
        // Capabilities are cached too; whether it is on is `connected`.
        ->and($status->supports($connection->fresh(), 'turn_input'))->toBeTrue();
});

it('reports no bridge version for BYOK', function () {
    $connection = Connection::create(['type' => Connection::TYPE_BYOK, 'name' => 'k']);

    expect(app(ConnectionStatus::class)->for($connection))
        ->toMatchArray(['bridge_version' => null, 'attachment_limits' => null, 'capabilities' => []]);
});

// --- bridge_prompt -----------------------------------------------------------

it('sends bridge_prompt as given, and default as absent', function (mixed $spec, ?array $wire) {
    $payload = AiRequestPayload::build(['request_id' => 'r', 'message' => 'm', 'bridge_prompt' => $spec]);

    expect($payload['bridge_prompt'] ?? null)->toBe($wire);
})->with([
    'append' => [['mode' => 'append', 'text' => 'Answer in Dutch.'], ['mode' => 'append', 'text' => 'Answer in Dutch.']],
    'replace' => [['mode' => 'replace', 'text' => 'Mine.'], ['mode' => 'replace', 'text' => 'Mine.']],
    'off' => [['mode' => 'off'], ['mode' => 'off']],
    'off as a string' => ['off', ['mode' => 'off']],
    'default' => [['mode' => 'default'], null],
    'absent' => [null, null],
]);

it('refuses a bridge_prompt the bridge would refuse', function (mixed $spec) {
    AiRequestPayload::build(['request_id' => 'r', 'message' => 'm', 'bridge_prompt' => $spec]);
})->with([
    'unknown mode' => [['mode' => 'shout']],
    'text with off' => [['mode' => 'off', 'text' => 'x']],
    'text with default' => [['text' => 'x']],
    'append without text' => [['mode' => 'append']],
    'replace with empty text' => [['mode' => 'replace', 'text' => '']],
    'too long' => [['mode' => 'append', 'text' => str_repeat('a', 8193)]],
    'not an object' => [42],
])->throws(InvalidArgumentException::class);

it('carries bridge_prompt across the relay', function () {
    $payload = AiRequestPayload::fromRelayBody([
        'message' => 'm',
        'bridge_prompt' => ['mode' => 'append', 'text' => 'Be brief.'],
    ], 'req-1');

    expect($payload['bridge_prompt'])->toBe(['mode' => 'append', 'text' => 'Be brief.']);
});

// --- pending_inputs on errors ---------------------------------------------------

function pendingTurn(BridgeConnectionManager $manager, string $rid, string $user): StreamHandler
{
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start', 'cancel', 'markCompleted')->byDefault();
    $handler = new StreamHandler($provider, $rid);
    $handler->setMode(ProviderMode::Bridge);
    $manager->registerPendingRequest($rid, $handler, $user);

    return $handler;
}

it('passes pending_inputs of a top-level error to the error callbacks and the buffer', function () {
    $rig = helloRig();
    $store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $store);
    $rig->manager->addConnection('user-1', 'conn-1');
    $handler = pendingTurn($rig->manager, 'req-1', 'user-1');
    $store->start('req-1', []);
    BufferingSink::attach($handler, $store);

    $seen = null;
    $handler->onError(function (string $code, string $message, array $meta = []) use (&$seen) {
        $seen = $meta;
    });

    $rig->handler->handleMessage('conn-1', null, json_encode([
        'type' => 'error', 'request_id' => 'req-1', 'code' => 'bridge_disconnected',
        'message' => 'gone', 'fatal' => false, 'pending_inputs' => ['m-2', 7, 'm-3'],
    ]));

    $events = $store->range('req-1');
    $last = end($events);

    expect($seen)->toBe(['pending_inputs' => ['m-2', 'm-3']])
        ->and(json_encode($last))->toContain('m-3');
});

it('records the unread messages a bridge replays for a turn already failed by its disconnect', function () {
    $rig = helloRig();
    $store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $store);
    $rig->manager->addConnection('user-1', 'conn-1');
    pendingTurn($rig->manager, 'req-1', 'user-1');
    $store->start('req-1', ['conversation_id' => '5']);

    // The socket closes: the turn is failed here and now.
    $rig->manager->removeConnectionByConnectionId('conn-1', 'transport_closed');

    // The bridge comes back and replays its own ending.
    $rig->manager->addConnection('user-1', 'conn-2');
    $rig->handler->handleMessage('conn-2', null, json_encode([
        'type' => 'error', 'request_id' => 'req-1', 'code' => 'bridge_disconnected',
        'message' => 'gone', 'fatal' => false, 'pending_inputs' => ['m-2'],
    ]));

    expect($store->status('req-1')['metadata']['pending_inputs'] ?? null)->toBe(['m-2']);
    Event::assertDispatched(TurnInputsReturned::class, fn ($e) => $e->requestId === 'req-1' && $e->pendingInputs === ['m-2']);
});

it('does not let another user\'s bridge write the unread list of a dropped turn', function () {
    $rig = helloRig();
    $store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $store);
    $rig->manager->addConnection('user-1', 'conn-1');
    $rig->manager->addConnection('user-2', 'conn-x');
    pendingTurn($rig->manager, 'req-1', 'user-1');
    $store->start('req-1', []);
    $rig->manager->removeConnectionByConnectionId('conn-1', 'transport_closed');

    $rig->handler->handleMessage('conn-x', null, json_encode([
        'type' => 'error', 'request_id' => 'req-1', 'code' => 'bridge_disconnected',
        'message' => 'gone', 'fatal' => false, 'pending_inputs' => ['m-2'],
    ]));

    expect($store->status('req-1')['metadata']['pending_inputs'] ?? null)->toBeNull();
    Event::assertNotDispatched(TurnInputsReturned::class);
});
