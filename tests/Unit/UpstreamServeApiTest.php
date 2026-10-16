<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Server\BridgeWebSocketServer;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The serve process's internal API for upstream MCP servers
|--------------------------------------------------------------------------
|
| A web worker cannot write to a bridge's socket; the serve process can. So the
| worker POSTs to /api/mcp and /api/upstream/*, the serve process sends the frame
| and holds the response open until the bridge answers (correlated by id or
| state), the bridge drops, or a timeout fires.
|
*/

const SERVE_STATE = 'Xb3kQ9vW2mN7pL4tR8sY1zA6cE5fH0jU';

/** A serve process with bridges key-1 (everything), key-old (no mcp_apps / upstream auth). */
function serveRig(float $timeout = 5.0): object
{
    Event::fake();
    config()->set('ai-bridge.server.mcp_request_timeout', $timeout);
    config()->set('ai-bridge.upstream_oauth.callback_timeout', $timeout);
    config()->set('ai-bridge.upstream_oauth.start_timeout', $timeout);

    $rig = new stdClass();
    $rig->manager = new BridgeConnectionManager();
    $rig->handler = new MessageHandler($rig->manager, app(TokenManager::class), new ToolRegistry());
    $rig->server = new BridgeWebSocketServer($rig->manager, $rig->handler, app(TokenManager::class));
    $rig->loop = new StreamSelectLoop();
    (new ReflectionProperty(BridgeWebSocketServer::class, 'loop'))->setValue($rig->server, $rig->loop);

    $rig->sent = [];
    $rig->answer = null;
    $rig->manager->setSendCallback(function (mixed $conn, array $payload) use ($rig): bool {
        $rig->sent[] = $payload;
        if ($rig->answer !== null) {
            ($rig->answer)($payload);
        }

        return true;
    });

    $rig->manager->addConnection('key-1', 'conn-1', 'socket-1');
    $rig->handler->handleMessage('conn-1', null, json_encode(['type' => 'hello', 'version' => '0.1', 'providers' => [],
        'mcp_apps' => ['spec' => '2026-01-26', 'revision' => 1], 'mcp_upstream_auth' => ['revision' => 1]]));
    $rig->manager->addConnection('key-old', 'conn-old', 'socket-old');
    $rig->handler->handleMessage('conn-old', null, json_encode(['type' => 'hello', 'version' => '0.1', 'providers' => []]));
    $rig->sent = [];

    $rig->from = fn (string $connectionId, array $frame) => $rig->handler->handleMessage($connectionId, null, json_encode($frame));

    return $rig;
}

/** POST to the serve process's internal API as a connection; every response written. */
function servePost(object $rig, string $path, array $body, string $as = 'key-1'): object
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

    $token = app(TokenManager::class)->generate($as, ['scope' => TokenManager::INTERNAL_RELAY_SCOPE], 60);
    $request = new PsrRequest('POST', $path, ['Authorization' => "Bearer {$token}"], json_encode($body));
    (new ReflectionMethod(BridgeWebSocketServer::class, 'handleHttpRequest'))->invoke($rig->server, $tcp, $request);

    return $out;
}

describe('POST /api/mcp', function () {
    it('sends mcp_request and answers with the mcp_result carrying the same id', function () {
        $rig = serveRig();
        $rig->answer = fn (array $frame) => ($rig->from)('conn-1', ['type' => MessageTypes::MCP_RESULT, 'id' => $frame['id'], 'result' => ['contents' => [['uri' => 'ui://weather/view.html', 'text' => '<html>']]]]);

        $out = servePost($rig, '/api/mcp', ['server' => 'weather', 'method' => 'resources/read', 'params' => ['uri' => 'ui://weather/view.html']]);

        expect($rig->sent)->toHaveCount(1)
            ->and($rig->sent[0])->toMatchArray(['type' => MessageTypes::MCP_REQUEST, 'server' => 'weather', 'method' => 'resources/read'])
            ->and((array) $rig->sent[0]['params'])->toBe(['uri' => 'ui://weather/view.html'])
            ->and($rig->sent[0]['id'])->toStartWith('mcp-')
            ->and($out->responses)->toBe([['status' => 200, 'body' => ['ok' => true, 'result' => ['contents' => [['uri' => 'ui://weather/view.html', 'text' => '<html>']]]]]]);
    });

    it('does not mix up two requests in flight', function () {
        $rig = serveRig();
        $a = servePost($rig, '/api/mcp', ['server' => 'weather', 'method' => 'tools/call', 'params' => ['name' => 'a', 'arguments' => []]]);
        $b = servePost($rig, '/api/mcp', ['server' => 'weather', 'method' => 'tools/call', 'params' => ['name' => 'b', 'arguments' => []]]);
        [$first, $second] = $rig->sent;

        ($rig->from)('conn-1', ['type' => MessageTypes::MCP_RESULT, 'id' => $second['id'], 'result' => ['content' => [['type' => 'text', 'text' => 'B']]]]);
        ($rig->from)('conn-1', ['type' => MessageTypes::MCP_RESULT, 'id' => $first['id'], 'error' => 'nope', 'code' => 'refused']);

        expect($a->responses[0]['body'])->toBe(['ok' => false, 'code' => 'refused', 'error' => 'nope'])
            ->and($b->responses[0]['body']['result']['content'][0]['text'])->toBe('B');
    });

    it('answers 504 timeout when the bridge never answers, and forgets the request', function () {
        $rig = serveRig(0.01);

        $out = servePost($rig, '/api/mcp', ['server' => 'weather', 'method' => 'tools/call', 'params' => ['name' => 'refresh']]);
        $rig->loop->run();

        expect($out->responses)->toHaveCount(1)
            ->and($out->responses[0]['status'])->toBe(504)
            ->and($out->responses[0]['body']['code'])->toBe('timeout')
            ->and($rig->manager->hasPendingExchange('mcp:'.$rig->sent[0]['id']))->toBeFalse();
    });

    it('refuses every other method, and a non-ui:// read, without sending anything', function () {
        $rig = serveRig();

        $cases = [
            [['server' => 'weather', 'method' => 'tools/list', 'params' => []], 'unsupported'],
            [['server' => 'weather', 'method' => 'resources/list', 'params' => []], 'unsupported'],
            [['server' => 'weather', 'method' => 'resources/read', 'params' => ['uri' => 'file:///etc/passwd']], 'refused'],
            [['server' => 'weather', 'method' => 'tools/call', 'params' => ['arguments' => []]], 'invalid_request'],
            [['server' => 'weather', 'method' => 'tools/call', 'params' => ['name' => 'x', 'arguments' => [1, 2]]], 'invalid_request'],
            [['method' => 'tools/call', 'params' => ['name' => 'x']], 'invalid_request'],
        ];

        foreach ($cases as [$body, $code]) {
            $out = servePost($rig, '/api/mcp', $body);
            expect($out->responses[0]['status'])->toBe(400)
                ->and($out->responses[0]['body']['code'])->toBe($code);
        }

        expect($rig->sent)->toBe([]);
    });

    it('refuses a bridge whose hello had no mcp_apps, and one not connected', function () {
        $rig = serveRig();
        $body = ['server' => 'weather', 'method' => 'resources/read', 'params' => ['uri' => 'ui://weather/view.html']];

        $old = servePost($rig, '/api/mcp', $body, 'key-old');
        $gone = servePost($rig, '/api/mcp', $body, 'key-nobody');

        expect($old->responses[0])->toMatchArray(['status' => 409])
            ->and($old->responses[0]['body']['code'])->toBe('mcp_apps_unsupported')
            ->and($gone->responses[0]['status'])->toBe(404)
            ->and($gone->responses[0]['body']['code'])->toBe('not_connected')
            ->and($rig->sent)->toBe([]);
    });

    it('answers not_connected at once when the bridge drops mid-request', function () {
        $rig = serveRig();
        $out = servePost($rig, '/api/mcp', ['server' => 'weather', 'method' => 'tools/call', 'params' => ['name' => 'refresh']]);

        $rig->manager->removeConnection('key-1', 'closed');

        expect($out->responses)->toHaveCount(1)
            ->and($out->responses[0]['status'])->toBe(404)
            ->and($out->responses[0]['body']['code'])->toBe('not_connected');
    });
});

describe('POST /api/upstream/*', function () {
    it('starts a sign-in and answers with the authorization URL the bridge opened', function () {
        $rig = serveRig();
        $rig->answer = fn () => ($rig->from)('conn-1', [
            'type' => MessageTypes::UPSTREAM_AUTH_REQUIRED, 'server' => 'kaizen', 'state' => SERVE_STATE,
            'authorization_url' => 'https://kaizen.example/oauth/authorize?state='.SERVE_STATE,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600), 'reason' => 'requested',
        ]);

        $out = servePost($rig, '/api/upstream/auth-start', ['server' => 'kaizen']);

        expect($rig->sent)->toBe([['type' => MessageTypes::UPSTREAM_AUTH_START, 'server' => 'kaizen']])
            ->and($out->responses[0]['status'])->toBe(200)
            ->and($out->responses[0]['body'])->toMatchArray(['ok' => true, 'server' => 'kaizen', 'reason' => 'requested']);
    });

    it('sends each person\'s start to their own bridge, even for the same upstream name', function () {
        $rig = serveRig();
        $rig->manager->addConnection('key-2', 'conn-2', 'socket-2');
        ($rig->from)('conn-2', ['type' => 'hello', 'version' => '0.1', 'providers' => [], 'mcp_upstream_auth' => ['revision' => 1]]);
        $rig->sent = [];

        $one = servePost($rig, '/api/upstream/auth-start', ['server' => 'kaizen'], 'key-1');
        $two = servePost($rig, '/api/upstream/auth-start', ['server' => 'kaizen'], 'key-2');

        expect($rig->sent)->toHaveCount(2);

        ($rig->from)('conn-2', ['type' => MessageTypes::UPSTREAM_AUTH_RESULT, 'server' => 'kaizen', 'state' => null, 'ok' => false, 'code' => 'not_oauth']);
        expect($one->responses)->toBe([])
            ->and($two->responses[0]['body']['code'])->toBe('not_oauth');

        ($rig->from)('conn-1', ['type' => MessageTypes::UPSTREAM_AUTH_RESULT, 'server' => 'kaizen', 'state' => null, 'ok' => false, 'code' => 'discovery_failed']);
        expect($one->responses[0]['body']['code'])->toBe('discovery_failed')
            ->and($two->responses)->toHaveCount(1);
    });

    it('passes on a start the bridge refused', function () {
        $rig = serveRig();
        $rig->answer = fn () => ($rig->from)('conn-1', ['type' => MessageTypes::UPSTREAM_AUTH_RESULT, 'server' => 'weather', 'state' => null, 'ok' => false, 'code' => 'not_oauth', 'error' => 'weather does not use OAuth.']);

        $out = servePost($rig, '/api/upstream/auth-start', ['server' => 'weather']);

        expect($out->responses[0]['body'])->toBe(['ok' => false, 'server' => 'weather', 'code' => 'not_oauth', 'error' => 'weather does not use OAuth.']);
    });

    it('sends one frame for two starts of the same upstream, and answers both', function () {
        $rig = serveRig();
        $a = servePost($rig, '/api/upstream/auth-start', ['server' => 'kaizen']);
        $b = servePost($rig, '/api/upstream/auth-start', ['server' => 'kaizen']);

        ($rig->from)('conn-1', ['type' => MessageTypes::UPSTREAM_AUTH_RESULT, 'server' => 'kaizen', 'ok' => false, 'code' => 'discovery_failed']);

        expect($rig->sent)->toHaveCount(1)
            ->and($a->responses[0]['body']['code'])->toBe('discovery_failed')
            ->and($b->responses[0]['body']['code'])->toBe('discovery_failed');
    });

    it('forwards the code and answers with the result for the same state', function () {
        $rig = serveRig();
        $rig->answer = fn (array $f) => ($rig->from)('conn-1', ['type' => MessageTypes::UPSTREAM_AUTH_RESULT, 'server' => 'kaizen', 'state' => $f['state'], 'ok' => true]);

        $out = servePost($rig, '/api/upstream/auth-code', ['server' => 'kaizen', 'state' => SERVE_STATE, 'code' => 'def502', 'iss' => 'https://kaizen.example', 'ignored' => 'x']);

        expect($rig->sent)->toBe([['type' => MessageTypes::UPSTREAM_AUTH_CODE, 'server' => 'kaizen', 'state' => SERVE_STATE, 'code' => 'def502', 'iss' => 'https://kaizen.example']])
            ->and($out->responses)->toBe([['status' => 200, 'body' => ['ok' => true, 'server' => 'kaizen']]]);
    });

    it('forwards a denial as error and error_description', function () {
        $rig = serveRig();
        servePost($rig, '/api/upstream/auth-code', ['server' => 'kaizen', 'state' => SERVE_STATE, 'error' => 'access_denied', 'error_description' => 'The user denied the request']);

        expect($rig->sent[0])->toBe(['type' => MessageTypes::UPSTREAM_AUTH_CODE, 'server' => 'kaizen', 'state' => SERVE_STATE, 'error' => 'access_denied', 'error_description' => 'The user denied the request']);
    });

    it('answers 504 when the bridge never reports the result', function () {
        $rig = serveRig(0.01);
        $out = servePost($rig, '/api/upstream/auth-code', ['server' => 'kaizen', 'state' => SERVE_STATE, 'code' => 'def502']);
        $rig->loop->run();

        expect($out->responses[0]['status'])->toBe(504)
            ->and($out->responses[0]['body']['code'])->toBe('timeout');
    });

    it('sends upstream_auth_forget and answers sent', function () {
        $rig = serveRig();
        $out = servePost($rig, '/api/upstream/auth-forget', ['server' => 'kaizen']);

        expect($rig->sent)->toBe([['type' => MessageTypes::UPSTREAM_AUTH_FORGET, 'server' => 'kaizen']])
            ->and($out->responses)->toBe([['status' => 200, 'body' => ['sent' => true]]]);
    });

    it('sends none of the upstream_auth frames to a bridge without mcp_upstream_auth', function () {
        $rig = serveRig();

        foreach (['/api/upstream/auth-start' => ['server' => 'k'], '/api/upstream/auth-forget' => ['server' => 'k'], '/api/upstream/auth-code' => ['server' => 'k', 'state' => SERVE_STATE, 'code' => 'c']] as $path => $body) {
            $out = servePost($rig, $path, $body, 'key-old');
            expect($out->responses[0]['status'])->toBe(409)
                ->and($out->responses[0]['body']['code'])->toBe('mcp_upstream_auth_unsupported');
        }

        expect($rig->sent)->toBe([]);
    });

    it('refuses a body without a server, or a code without code and error', function () {
        $rig = serveRig();

        expect(servePost($rig, '/api/upstream/auth-start', [])->responses[0]['status'])->toBe(400)
            ->and(servePost($rig, '/api/upstream/auth-code', ['server' => 'k', 'state' => SERVE_STATE])->responses[0]['status'])->toBe(400)
            ->and($rig->sent)->toBe([]);
    });

    it('reports the upstream list on /api/status', function () {
        $rig = serveRig();
        ($rig->from)('conn-1', ['type' => MessageTypes::UPSTREAM_STATUS, 'upstreams' => [['server' => 'kaizen', 'transport' => 'http', 'origin' => 'https://kaizen.example', 'auth' => 'oauth', 'state' => 'needs_auth', 'tools' => 0, 'error' => null]]]);

        $tcp = Mockery::mock(ConnectionInterface::class);
        $written = '';
        $tcp->shouldReceive('write')->andReturnUsing(function (string $raw) use (&$written) { $written = $raw; return true; });
        $tcp->shouldReceive('end');
        $token = app(TokenManager::class)->generate('key-1', ['scope' => TokenManager::INTERNAL_RELAY_SCOPE], 60);
        (new ReflectionMethod(BridgeWebSocketServer::class, 'handleHttpRequest'))->invoke($rig->server, $tcp, new PsrRequest('GET', '/api/status', ['Authorization' => "Bearer {$token}"]));

        $body = json_decode(explode("\r\n\r\n", $written, 2)[1], true);
        expect($body['upstream_status'][0]['server'])->toBe('kaizen')
            ->and($body['bridge']['mcp_upstream_auth'])->toBe(['revision' => 1]);
    });
});
