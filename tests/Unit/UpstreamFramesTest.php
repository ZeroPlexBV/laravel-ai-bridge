<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Connections\ConnectionStatus;
use Tetrix\AiBridge\Events\UpstreamAuthRequired;
use Tetrix\AiBridge\Events\UpstreamStatusChanged;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\Upstreams\PendingSignIns;
use Tetrix\AiBridge\Upstreams\UpstreamOAuth;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Upstream MCP servers: the bridge's frames as the serve process reads them
|--------------------------------------------------------------------------
|
| hello.mcp_upstream_auth, welcome.upstream_oauth, upstream_status,
| upstream_auth_required, upstream_auth_result and mcp_result. Each arrives in
| the ReactPHP loop, so a malformed one is dropped, never thrown.
|
*/

const STATE_A = 'Xb3kQ9vW2mN7pL4tR8sY1zA6cE5fH0jU';
const STATE_B = 'Zz9yX8wV7uT6sR5qP4oN3mL2kJ1iH0gF';

function upstreamRig(array $hello = ['mcp_upstream_auth' => ['revision' => 1], 'mcp_apps' => ['spec' => '2026-01-26', 'revision' => 1]]): object
{
    $rig = new stdClass();
    $rig->connection = Connection::create(['type' => Connection::TYPE_BRIDGE, 'connection_key' => 'key-1', 'name' => 'laptop']);
    $rig->manager = new BridgeConnectionManager();
    $rig->manager->addConnection('key-1', 'conn-1', null, [], null, $rig->connection->id);
    $rig->manager->addConnection('key-2', 'conn-2');
    $rig->handler = new MessageHandler($rig->manager, app(TokenManager::class), new ToolRegistry());
    $rig->send = fn (array $frame, string $from = 'conn-1') => $rig->handler->handleMessage($from, null, json_encode($frame));
    $rig->welcome = ($rig->send)(['type' => MessageTypes::HELLO, 'version' => '0.1', 'providers' => []] + $hello);

    return $rig;
}

function requiredFrame(array $over = []): array
{
    return $over + [
        'type' => MessageTypes::UPSTREAM_AUTH_REQUIRED,
        'server' => 'kaizen',
        'state' => STATE_A,
        'authorization_url' => 'https://kaizen.example/oauth/authorize?state='.STATE_A,
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600),
        'reason' => 'no_token',
    ];
}

describe('hello and welcome', function () {
    it('records mcp_upstream_auth from hello, and null when absent or not an object', function () {
        expect(upstreamRig()->manager->getBridgeInfo('key-1')['mcp_upstream_auth'])->toBe(['revision' => 1]);

        foreach ([[], ['mcp_upstream_auth' => true], ['mcp_upstream_auth' => [1, 2]]] as $hello) {
            expect(upstreamRig($hello)->manager->getBridgeInfo('key-1')['mcp_upstream_auth'])->toBeNull();
        }
    });

    it('sends the absolute callback URL as welcome.upstream_oauth', function () {
        $welcome = upstreamRig()->welcome;

        expect($welcome['upstream_oauth'])->toBe(['redirect_uri' => route(UpstreamOAuth::CALLBACK_ROUTE)])
            ->and($welcome['upstream_oauth']['redirect_uri'])->toEndWith('/ai-bridge/upstream-oauth/callback')
            ->and($welcome['upstream_oauth']['redirect_uri'])->toStartWith('http');
    });

    it('builds the redirect_uri from APP_URL, never from a request host', function () {
        config()->set('app.url', 'https://app.example/');
        $this->get('http://evil.example/anything');

        expect(UpstreamOAuth::redirectUri())->toBe('https://app.example/ai-bridge/upstream-oauth/callback');
    });

    it('sends no redirect_uri that is not an absolute http(s) URL', function () {
        config()->set('ai-bridge.upstream_oauth.redirect_uri', '/relative/callback');
        expect(upstreamRig()->welcome)->not->toHaveKey('upstream_oauth');

        config()->set('ai-bridge.upstream_oauth.redirect_uri', null);
        config()->set('app.url', 'localhost');
        expect(UpstreamOAuth::redirectUri())->toBeNull();
    });

    it('sends a configured redirect_uri instead, and nothing when switched off', function () {
        config()->set('ai-bridge.upstream_oauth.redirect_uri', 'https://app.example/cb');
        expect(upstreamRig()->welcome['upstream_oauth'])->toBe(['redirect_uri' => 'https://app.example/cb']);

        config()->set('ai-bridge.upstream_oauth.enabled', false);
        expect(upstreamRig()->welcome)->not->toHaveKey('upstream_oauth');
    });
});

describe('upstream_status', function () {
    it('keeps the complete list, reduced to the documented fields and values', function () {
        Event::fake([UpstreamStatusChanged::class]);
        $rig = upstreamRig();

        ($rig->send)(['type' => MessageTypes::UPSTREAM_STATUS, 'upstreams' => [
            ['server' => 'kaizen', 'transport' => 'http', 'origin' => 'https://Kaizen.example:8443/mcp?x=1', 'auth' => 'oauth', 'state' => 'needs_auth', 'tools' => 0, 'error' => null, 'extra' => 'dropped'],
            ['server' => 'weather', 'transport' => 'stdio', 'origin' => 'https://leak.example', 'auth' => 'secrets', 'state' => 'connected', 'tools' => 3, 'error' => null],
            ['server' => 'odd', 'transport' => 'carrier-pigeon', 'auth' => 'magic', 'state' => 'sleeping', 'tools' => -1, 'error' => ['x']],
            ['transport' => 'http'],
            'not an object',
        ]]);

        expect($rig->manager->getUpstreams('key-1'))->toBe([
            ['server' => 'kaizen', 'transport' => 'http', 'origin' => 'https://kaizen.example:8443', 'auth' => 'oauth', 'state' => 'needs_auth', 'tools' => 0, 'error' => null],
            ['server' => 'weather', 'transport' => 'stdio', 'origin' => null, 'auth' => 'secrets', 'state' => 'connected', 'tools' => 3, 'error' => null],
            ['server' => 'odd', 'transport' => null, 'origin' => null, 'auth' => null, 'state' => 'unavailable', 'tools' => 0, 'error' => null],
        ]);

        Event::assertDispatched(UpstreamStatusChanged::class, fn (UpstreamStatusChanged $e) => $e->connectionKey === 'key-1'
            && $e->connectionId === $rig->connection->id
            && count($e->upstreams) === 3);
    });

    it('fires UpstreamStatusChanged only when the list changed', function () {
        Event::fake([UpstreamStatusChanged::class]);
        $rig = upstreamRig();
        $frame = ['type' => MessageTypes::UPSTREAM_STATUS, 'upstreams' => [['server' => 'kaizen', 'transport' => 'http', 'origin' => 'https://kaizen.example', 'auth' => 'oauth', 'state' => 'connected', 'tools' => 4, 'error' => null]]];

        ($rig->send)($frame);
        ($rig->send)($frame);
        $frame['upstreams'][0]['tools'] = 5;
        ($rig->send)($frame);

        Event::assertDispatchedTimes(UpstreamStatusChanged::class, 2);
    });

    it('survives malformed frames and ignores an unauthenticated sender', function () {
        $rig = upstreamRig();

        expect(($rig->send)(['type' => MessageTypes::UPSTREAM_STATUS, 'upstreams' => 'nope']))->toBeNull()
            ->and(($rig->send)(['type' => MessageTypes::UPSTREAM_STATUS]))->toBeNull()
            ->and(($rig->send)(['type' => MessageTypes::UPSTREAM_STATUS, 'upstreams' => [['server' => 'x']]], 'conn-unknown'))->toBeNull()
            ->and($rig->manager->getUpstreams('key-1'))->toBe([]);
    });

    it('reports mcp_upstream_auth and upstream_status on the connection status, kept while the machine is off', function () {
        $connection = Connection::create(['type' => Connection::TYPE_BRIDGE, 'connection_key' => 'key-s']);
        $upstreams = [['server' => 'kaizen', 'transport' => 'http', 'origin' => 'https://kaizen.example', 'auth' => 'oauth', 'state' => 'needs_auth', 'tools' => 0, 'error' => null]];

        Http::fake(['*/api/status' => Http::sequence()
            ->push([
                'connected' => true,
                'providers' => [],
                'bridge' => MessageHandler::bridgeInfoFromHello(['mcp_upstream_auth' => ['revision' => 1]]),
                'upstream_status' => $upstreams,
            ])
            ->push(['connected' => false])]);

        $status = app(ConnectionStatus::class);

        expect($status->for($connection))->toMatchArray(['connected' => true, 'mcp_upstream_auth' => true, 'upstream_status' => $upstreams])
            ->and($status->for($connection->fresh()))->toMatchArray(['connected' => false, 'mcp_upstream_auth' => true, 'upstream_status' => $upstreams]);
    });
});

describe('upstream_auth_required', function () {
    it('keeps the pending sign-in and fires UpstreamAuthRequired', function () {
        Event::fake([UpstreamAuthRequired::class]);
        $rig = upstreamRig();

        expect(($rig->send)(requiredFrame()))->toBeNull();

        $signIn = app(PendingSignIns::class)->find(STATE_A);
        expect($signIn)->toMatchArray([
            'state' => STATE_A,
            'connection_key' => 'key-1',
            'connection_id' => $rig->connection->id,
            'server' => 'kaizen',
            'reason' => 'no_token',
        ])->and(app(PendingSignIns::class)->forConnectionKey('key-1'))->toHaveKey('kaizen');

        Event::assertDispatched(UpstreamAuthRequired::class, fn (UpstreamAuthRequired $e) => $e->server === 'kaizen'
            && $e->connectionId === $rig->connection->id
            && str_contains($e->authorizationUrl, STATE_A)
            && $e->reason === 'no_token');
    });

    it('replaces an earlier sign-in for the same upstream, whose state then stops working', function () {
        $rig = upstreamRig();

        ($rig->send)(requiredFrame());
        ($rig->send)(requiredFrame(['state' => STATE_B, 'authorization_url' => 'https://kaizen.example/oauth/authorize?state='.STATE_B, 'reason' => 'refresh_failed']));

        expect(app(PendingSignIns::class)->find(STATE_A))->toBeNull()
            ->and(app(PendingSignIns::class)->find(STATE_B)['reason'])->toBe('refresh_failed')
            ->and(app(PendingSignIns::class)->forConnectionKey('key-1'))->toHaveCount(1);
    });

    it('caps the expiry at fifteen minutes', function () {
        $rig = upstreamRig();
        ($rig->send)(requiredFrame(['expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400)]));

        expect(strtotime(app(PendingSignIns::class)->find(STATE_A)['expires_at']))->toBeLessThanOrEqual(time() + PendingSignIns::MAX_TTL);
    });

    it('drops a malformed or already expired one without keeping or announcing it', function () {
        Event::fake([UpstreamAuthRequired::class]);
        $rig = upstreamRig();

        foreach ([
            ['server' => ''],
            ['server' => ['kaizen']],
            ['state' => 'short'],
            ['authorization_url' => 'javascript:alert(1)'],
            ['authorization_url' => 42],
            ['expires_at' => 'whenever'],
            ['expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 5)],
        ] as $bad) {
            expect(($rig->send)(requiredFrame($bad)))->toBeNull();
        }

        expect(app(PendingSignIns::class)->find(STATE_A))->toBeNull();
        Event::assertNotDispatched(UpstreamAuthRequired::class);
    });
});

describe('answers handed to whoever waits', function () {
    it('routes upstream_auth_result by state, and a stateless failure to the waiting start', function () {
        $rig = upstreamRig();
        $got = [];
        $rig->manager->registerPendingExchange('auth:'.STATE_A, 'key-1', function (array $a) use (&$got) { $got['code'] = $a; }, []);
        $rig->manager->registerPendingExchange('auth-start:kaizen', 'key-1', function (array $a) use (&$got) { $got['start'] = $a; }, []);

        ($rig->send)(['type' => MessageTypes::UPSTREAM_AUTH_RESULT, 'server' => 'kaizen', 'state' => STATE_A, 'ok' => true]);
        ($rig->send)(['type' => MessageTypes::UPSTREAM_AUTH_RESULT, 'server' => 'kaizen', 'state' => null, 'ok' => false, 'code' => 'discovery_failed', 'error' => 'No metadata.']);

        expect($got['code'])->toBe(['ok' => true, 'server' => 'kaizen'])
            ->and($got['start'])->toBe(['ok' => false, 'server' => 'kaizen', 'code' => 'discovery_failed', 'error' => 'No metadata.']);
    });

    it('announces a failure the bridge reports without a state, whether or not anyone waits', function () {
        Event::fake([\Tetrix\AiBridge\Events\UpstreamAuthCompleted::class]);
        $rig = upstreamRig();

        // The bridge's own first prompt after connecting could not do discovery: nobody waits.
        ($rig->send)(['type' => MessageTypes::UPSTREAM_AUTH_RESULT, 'server' => 'kaizen', 'state' => null, 'ok' => false, 'code' => 'discovery_failed', 'error' => 'No metadata.']);

        \Illuminate\Support\Facades\Event::assertDispatched(\Tetrix\AiBridge\Events\UpstreamAuthCompleted::class, fn ($e) => ! $e->ok
            && $e->server === 'kaizen' && $e->code === 'discovery_failed' && $e->connectionKey === 'key-1'
            && $e->connectionId === $rig->connection->id && $e->userId === null);

        // A success with a state is announced by the callback, not here.
        ($rig->send)(['type' => MessageTypes::UPSTREAM_AUTH_RESULT, 'server' => 'kaizen', 'state' => STATE_A, 'ok' => true]);
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\Tetrix\AiBridge\Events\UpstreamAuthCompleted::class, 1);
    });

    it('turns an unknown auth code into failed', function () {
        $rig = upstreamRig();
        $got = null;
        $rig->manager->registerPendingExchange('auth:'.STATE_A, 'key-1', function (array $a) use (&$got) { $got = $a; }, []);

        ($rig->send)(['type' => MessageTypes::UPSTREAM_AUTH_RESULT, 'server' => 'kaizen', 'state' => STATE_A, 'ok' => 'yes', 'code' => 'brand_new']);

        expect($got)->toBe(['ok' => false, 'server' => 'kaizen', 'code' => 'failed', 'error' => null]);
    });

    it('answers a waiting start with the sign-in the bridge opened', function () {
        $rig = upstreamRig();
        $got = null;
        $rig->manager->registerPendingExchange('auth-start:kaizen', 'key-1', function (array $a) use (&$got) { $got = $a; }, []);

        ($rig->send)(requiredFrame(['reason' => 'requested']));

        expect($got)->toMatchArray(['ok' => true, 'server' => 'kaizen', 'reason' => 'requested'])
            ->and($got['authorization_url'])->toContain(STATE_A);
    });

    it('routes mcp_result by id, with the result unchanged or the error code checked', function () {
        $rig = upstreamRig();
        $got = [];
        foreach (['m1', 'm2', 'm3'] as $id) {
            $rig->manager->registerPendingExchange('mcp:'.$id, 'key-1', function (array $a) use (&$got, $id) { $got[$id] = $a; }, []);
        }

        $result = ['contents' => [['uri' => 'ui://weather/view.html', 'mimeType' => 'text/html;profile=mcp-app', 'text' => '<!doctype html>', '_meta' => ['ui' => ['csp' => ['connectDomains' => ['https://api.weather.example']]]]]]];
        ($rig->send)(['type' => MessageTypes::MCP_RESULT, 'id' => 'm1', 'result' => $result]);
        ($rig->send)(['type' => MessageTypes::MCP_RESULT, 'id' => 'm2', 'error' => 'refresh is not callable from a view', 'code' => 'refused']);
        ($rig->send)(['type' => MessageTypes::MCP_RESULT, 'id' => 'm3', 'error' => 42, 'code' => 'made_up']);

        expect($got['m1'])->toBe(['ok' => true, 'result' => $result])
            ->and($got['m2'])->toBe(['ok' => false, 'code' => 'refused', 'error' => 'refresh is not callable from a view'])
            ->and($got['m3'])->toBe(['ok' => false, 'code' => 'upstream_error', 'error' => 'The upstream request failed.']);
    });

    it('lets no other bridge answer an exchange, and answers each waiter once', function () {
        $rig = upstreamRig();
        $calls = 0;
        $rig->manager->registerPendingExchange('mcp:m1', 'key-1', function () use (&$calls) { $calls++; }, []);

        ($rig->send)(['type' => MessageTypes::MCP_RESULT, 'id' => 'm1', 'result' => []], 'conn-2');
        expect($calls)->toBe(0)->and($rig->manager->hasPendingExchange('mcp:m1'))->toBeTrue();

        ($rig->send)(['type' => MessageTypes::MCP_RESULT, 'id' => 'm1', 'result' => []]);
        ($rig->send)(['type' => MessageTypes::MCP_RESULT, 'id' => 'm1', 'result' => []]);
        expect($calls)->toBe(1)->and($rig->manager->hasPendingExchange('mcp:m1'))->toBeFalse();
    });

    it('fails a waiter at once when its bridge goes away', function () {
        $rig = upstreamRig();
        $got = null;
        $rig->manager->registerPendingExchange('mcp:m1', 'key-1', function (array $a) use (&$got) { $got = $a; }, ['ok' => false, 'code' => 'not_connected']);

        $rig->manager->removeConnection('key-1', 'closed');

        expect($got)->toBe(['ok' => false, 'code' => 'not_connected'])
            ->and($rig->manager->hasPendingExchange('mcp:m1'))->toBeFalse();
    });

    it('does not take the serve process down on malformed answers', function () {
        $rig = upstreamRig();

        foreach ([
            ['type' => MessageTypes::MCP_RESULT],
            ['type' => MessageTypes::MCP_RESULT, 'id' => ['x']],
            ['type' => MessageTypes::UPSTREAM_AUTH_RESULT, 'server' => null, 'ok' => true],
            ['type' => MessageTypes::UPSTREAM_AUTH_RESULT, 'server' => 'k', 'state' => ['x'], 'ok' => true],
            ['type' => MessageTypes::UPSTREAM_AUTH_REQUIRED],
        ] as $frame) {
            expect(($rig->send)($frame))->toBeNull();
        }
    });
});
