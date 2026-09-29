<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Connections\ConnectionStatus;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| What is left of the subscription a bridge is signed in as
|--------------------------------------------------------------------------
|
| The credential that could answer this lives on the operator's machine, with
| the CLI, and the server deliberately never sees it. So the server asks and the
| bridge answers with figures only.
|
| Two properties are worth protecting here. A reply must reach whoever is
| waiting for it, exactly once, and a malformed one must not take the serve
| process down — it arrives inside a ReactPHP data callback with no try/catch
| above it. And a bridge that answers with nothing usable must not be presented
| as "you have used none of it": an empty allowance and an unreadable answer look
| identical on a screen and are completely different facts.
|
*/

function usageHandler(BridgeConnectionManager $manager): MessageHandler
{
    return new MessageHandler(
        connectionManager: $manager,
        tokenManager: app(TokenManager::class),
        toolRegistry: new ToolRegistry(),
    );
}

/** A connected bridge, past the handshake. */
function usageManager(): BridgeConnectionManager
{
    $manager = new BridgeConnectionManager();
    $token = app(TokenManager::class)->generate('user-1');

    usageHandler($manager)->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '0.1',
        'token' => $token,
        'providers' => [],
    ]));

    return $manager;
}

/** Deliver a usage_result frame and return whatever was handed to the waiter. */
function deliverUsage(BridgeConnectionManager $manager, array $frame, string $id = 'usage-1'): ?array
{
    $answer = null;

    $manager->registerPendingUsage($id, 'user-1', function (array $a) use (&$answer): void {
        $answer = $a;
    });

    usageHandler($manager)->handleMessage('conn-1', null, json_encode($frame + [
        'type' => MessageTypes::USAGE_RESULT,
        'id' => $id,
    ]));

    return $answer;
}

it('hands the figures to whoever asked, keeping the order the bridge sent', function () {
    $manager = usageManager();

    $answer = deliverUsage($manager, [
        'ok' => true,
        'limits' => [
            ['label' => 'Current session', 'percent' => 32, 'resets_at' => '2026-09-21T11:10:00+00:00', 'kind' => 'session', 'group' => 'session'],
            ['label' => 'This week', 'percent' => 43, 'kind' => 'weekly_all', 'group' => 'weekly'],
            ['label' => 'Fable this week', 'percent' => 0],
        ],
    ]);

    expect($answer['ok'])->toBeTrue();
    expect(array_column($answer['limits'], 'label'))
        ->toBe(['Current session', 'This week', 'Fable this week']);
    expect($answer['limits'][0])->toMatchArray([
        'label' => 'Current session',
        'percent' => 32,
        'resets_at' => '2026-09-21T11:10:00+00:00',
        'kind' => 'session',
    ]);
    // Optional fields are absent rather than null when the bridge did not send them.
    expect($answer['limits'][2])->toBe(['label' => 'Fable this week', 'percent' => 0]);
});

it('passes a window kind it has never met straight through', function () {
    // Which allowances exist is the vendor's to change. Anything this package recognised by
    // name would be the thing that broke; it recognises nothing.
    $manager = usageManager();

    $answer = deliverUsage($manager, [
        'ok' => true,
        'limits' => [['label' => 'Lunar cycle', 'percent' => 12, 'kind' => 'lunar_cycle', 'group' => 'lunar']],
    ]);

    expect($answer['ok'])->toBeTrue();
    expect($answer['limits'][0]['label'])->toBe('Lunar cycle');
    expect($answer['limits'][0]['kind'])->toBe('lunar_cycle');
});

it('clamps a percentage and drops a row with no label or no figure', function () {
    $manager = usageManager();

    $answer = deliverUsage($manager, [
        'ok' => true,
        'limits' => [
            ['label' => 'Over', 'percent' => 140],
            ['label' => 'Under', 'percent' => -5],
            ['label' => 'Fractional', 'percent' => 42.6],
            ['label' => '', 'percent' => 10],
            ['percent' => 10],
            ['label' => 'No figure'],
            'not an array',
        ],
    ]);

    expect(array_column($answer['limits'], 'percent'))->toBe([100, 0, 43]);
});

it('reports a bridge that said ok but sent nothing usable as a failure, not as nothing used', function () {
    $manager = usageManager();

    expect(deliverUsage($manager, ['ok' => true, 'limits' => []]))
        ->toBe(['ok' => false, 'reason' => 'failed']);

    expect(deliverUsage($manager, ['ok' => true, 'limits' => [['label' => 'No figure']]], 'usage-2'))
        ->toBe(['ok' => false, 'reason' => 'failed']);
});

it('passes on the reason a bridge gives for having nothing to report', function () {
    $manager = usageManager();

    foreach (['unsupported', 'no_credential', 'failed'] as $i => $reason) {
        expect(deliverUsage($manager, ['ok' => false, 'reason' => $reason], "usage-{$i}"))
            ->toBe(['ok' => false, 'reason' => $reason]);
    }
});

it('answers a waiter exactly once, so a duplicate reply cannot write twice', function () {
    // A confused bridge, or a reply that raced the timeout, must not reach a response that
    // has already been written and closed.
    $manager = usageManager();
    $calls = 0;

    $manager->registerPendingUsage('usage-dup', 'user-1', function () use (&$calls): void {
        $calls++;
    });

    $frame = ['type' => MessageTypes::USAGE_RESULT, 'id' => 'usage-dup', 'ok' => false, 'reason' => 'failed'];
    usageHandler($manager)->handleMessage('conn-1', null, json_encode($frame));
    usageHandler($manager)->handleMessage('conn-1', null, json_encode($frame));

    expect($calls)->toBe(1);
});

it('does not take the serve process down on a malformed frame', function () {
    $manager = usageManager();

    $malformed = [
        ['ok' => true, 'limits' => 'not an array'],
        ['ok' => true, 'limits' => [42]],
        ['ok' => 'yes'],
        ['limits' => [['label' => ['array'], 'percent' => 10]]],
        [],
    ];

    foreach ($malformed as $frame) {
        expect(usageHandler($manager)->handleMessage('conn-1', null, json_encode($frame + [
            'type' => MessageTypes::USAGE_RESULT,
            'id' => 'usage-malformed',
        ])))->toBeNull();
    }
});

it('ignores a frame with no id, because nothing could be waiting for it', function () {
    $manager = usageManager();

    expect(usageHandler($manager)->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::USAGE_RESULT,
        'ok' => true,
        'limits' => [['label' => 'This week', 'percent' => 10]],
    ])))->toBeNull();
});

it('ignores a frame from a connection that never completed the handshake', function () {
    $manager = new BridgeConnectionManager();
    $calls = 0;

    $manager->registerPendingUsage('usage-1', 'user-1', function () use (&$calls): void {
        $calls++;
    });

    expect(usageHandler($manager)->handleMessage('conn-unknown', null, json_encode([
        'type' => MessageTypes::USAGE_RESULT,
        'id' => 'usage-1',
        'ok' => true,
        'limits' => [['label' => 'This week', 'percent' => 10]],
    ])))->toBeNull();

    expect($calls)->toBe(0);
});

it('resolving an answer nobody is waiting for is harmless', function () {
    $manager = usageManager();

    expect($manager->resolvePendingUsage('never-asked', 'user-1', ['ok' => true]))->toBeFalse();
});

it('reports a bridge that is not connected, without waiting on it', function () {
    Http::fake([
        '*/api/usage' => Http::response([
            'error' => 'bridge_not_connected',
            'message' => 'No bridge is connected for this user.',
        ], 404),
    ]);

    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'name' => 'Some machine',
        'connection_key' => 'user-1',
    ]);

    expect(app(ConnectionStatus::class)->usage($connection))
        ->toBe(['ok' => false, 'reason' => 'not_connected']);
});

it('reports a bridge that never answered as failed, not unsupported', function () {
    // Covers an older bridge that does not know the frame AND one that is simply wedged or
    // has just dropped. These are NOT the same fact to the reader, which is the whole point:
    // `unsupported` means "this CLI has no such notion" — permanent, nothing to retry —
    // while a bridge that timed out may well answer the same question a minute later.
    // Reporting the retryable case as the permanent one is the more expensive way to be
    // wrong, because a screen showing it has no reason to ask again.
    Http::fake(['*/api/usage' => Http::response(['error' => 'bridge_did_not_answer'], 504)]);

    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'name' => 'Old machine',
        'connection_key' => 'user-1',
    ]);

    expect(app(ConnectionStatus::class)->usage($connection))
        ->toBe(['ok' => false, 'reason' => 'failed']);
});

it('surfaces the figures through the connection read path', function () {
    Http::fake(['*/api/usage' => Http::response([
        'ok' => true,
        'limits' => [['label' => 'This week', 'percent' => 43]],
    ], 200)]);

    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'name' => 'Some machine',
        'connection_key' => 'user-1',
    ]);

    $usage = app(ConnectionStatus::class)->usage($connection);

    expect($usage['ok'])->toBeTrue();
    expect($usage['limits'][0]['label'])->toBe('This week');
});

it('stores nothing about usage on the connection', function () {
    // Unlike posture and providers, which are cached so a screen survives a rolling deploy,
    // a usage figure from an hour ago is worse than none because it reads as current. There
    // is deliberately no last_usage column to match last_posture.
    Http::fake(['*/api/usage' => Http::response([
        'ok' => true,
        'limits' => [['label' => 'This week', 'percent' => 43]],
    ], 200)]);

    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'name' => 'Some machine',
        'connection_key' => 'user-1',
    ]);

    app(ConnectionStatus::class)->usage($connection);

    expect(array_keys($connection->fresh()->getAttributes()))->not->toContain('last_usage');
});

// --- ownership: a request id is a secret, not an authorization check ---

it('refuses a usage_result from a different user, even with the right id', function () {
    // The attack this closes: every bridge on this server shares one id space for pending
    // questions. Before, resolvePendingUsage() keyed on the wire-supplied id alone, so any
    // authenticated bridge naming another user's request id answered that user's question
    // with figures of its choosing. The id being 64 bits of random_bytes made that hard,
    // not impossible — and secrecy of an identifier is not an access control.
    $manager = usageManager();

    $answer = null;
    $manager->registerPendingUsage('usage-secret', 'user-1', function (array $a) use (&$answer): void {
        $answer = $a;
    });

    // A second bridge, properly authenticated, as somebody else.
    $token = app(TokenManager::class)->generate('user-2');
    usageHandler($manager)->handleMessage('conn-2', null, json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '0.1',
        'token' => $token,
        'providers' => [],
    ]));

    usageHandler($manager)->handleMessage('conn-2', null, json_encode([
        'type' => MessageTypes::USAGE_RESULT,
        'id' => 'usage-secret',
        'ok' => true,
        'limits' => [['label' => 'Fabricated', 'percent' => 3]],
    ]));

    expect($answer)->toBeNull();

    // And the question is still open for the user who actually asked it.
    expect($manager->resolvePendingUsage('usage-secret', 'user-1', ['ok' => true]))->toBeTrue();
});

it('does not leave a waiter hanging when the bridge disconnects', function () {
    // Otherwise the asker waits out the full usage_timeout to be told nothing, when the
    // answer — the machine is gone — was known the moment the socket closed.
    $manager = usageManager();

    $answer = null;
    $manager->registerPendingUsage('usage-dropped', 'user-1', function (array $a) use (&$answer): void {
        $answer = $a;
    });

    $manager->removeConnection('user-1', 'bridge_disconnected');

    expect($answer)->toBe(['ok' => false, 'reason' => 'not_connected']);
});

it('sweeps only the disconnecting user’s questions', function () {
    $manager = usageManager();

    $mine = null;
    $theirs = null;
    $manager->registerPendingUsage('usage-mine', 'user-1', function (array $a) use (&$mine): void {
        $mine = $a;
    });
    $manager->registerPendingUsage('usage-theirs', 'user-2', function (array $a) use (&$theirs): void {
        $theirs = $a;
    });

    $manager->removeConnection('user-1', 'bridge_disconnected');

    expect($mine)->toBe(['ok' => false, 'reason' => 'not_connected']);
    expect($theirs)->toBeNull();
});

it('does not pass an unknown reason through to the application', function () {
    // `reason` is an enum the app branches on and renders. A value it has never heard of is
    // indistinguishable from a bug in the app, so anything off the documented list becomes
    // `failed` — the honest summary of "it did not work and I cannot tell you more".
    $answer = deliverUsage(usageManager(), [
        'ok' => false,
        'reason' => 'teapot',
    ]);

    expect($answer)->toBe(['ok' => false, 'reason' => 'failed']);
});

it('still passes the documented reasons through untouched', function () {
    foreach (['unsupported', 'no_credential', 'failed'] as $reason) {
        expect(deliverUsage(usageManager(), ['ok' => false, 'reason' => $reason]))
            ->toBe(['ok' => false, 'reason' => $reason]);
    }
});

it('passes a rate limit through as its own reason, with when to ask again', function () {
    // ai-bridge 0.21 reports the usage endpoint's 429 as a plain `failed`; a bridge that tells
    // them apart is heard, so the application can wait as long as it was asked to.
    expect(deliverUsage(usageManager(), ['ok' => false, 'reason' => 'rate_limited', 'retry_after' => 61.2]))
        ->toBe(['ok' => false, 'reason' => 'rate_limited', 'retry_after' => 62])
        ->and(deliverUsage(usageManager(), ['ok' => false, 'reason' => 'rate_limited'], 'usage-2'))
        ->toBe(['ok' => false, 'reason' => 'rate_limited']);
});

it('bounds retry_after and ignores it on anything but a rate limit', function () {
    expect(deliverUsage(usageManager(), ['ok' => false, 'reason' => 'rate_limited', 'retry_after' => 10 ** 9]))
        ->toBe(['ok' => false, 'reason' => 'rate_limited', 'retry_after' => 86400])
        ->and(deliverUsage(usageManager(), ['ok' => false, 'reason' => 'rate_limited', 'retry_after' => -5], 'usage-2'))
        ->toBe(['ok' => false, 'reason' => 'rate_limited'])
        ->and(deliverUsage(usageManager(), ['ok' => false, 'reason' => 'rate_limited', 'retry_after' => 'soon'], 'usage-3'))
        ->toBe(['ok' => false, 'reason' => 'rate_limited'])
        ->and(deliverUsage(usageManager(), ['ok' => false, 'reason' => 'failed', 'retry_after' => 30], 'usage-4'))
        ->toBe(['ok' => false, 'reason' => 'failed']);
});

it('hands a rate limit and its retry_after on to the application', function () {
    Http::fake(['*/api/usage' => Http::response(['ok' => false, 'reason' => 'rate_limited', 'retry_after' => 120])]);

    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'name' => 'Busy machine',
        'connection_key' => 'user-1',
    ]);

    expect(app(ConnectionStatus::class)->usage($connection))
        ->toBe(['ok' => false, 'reason' => 'rate_limited', 'retry_after' => 120]);
});
