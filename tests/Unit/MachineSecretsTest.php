<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Secrets\MachineSecrets;
use Tetrix\AiBridge\Secrets\SecretFrames;
use Tetrix\AiBridge\Server\BridgeWebSocketServer;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Secrets on the machine
|--------------------------------------------------------------------------
|
| The web worker POSTs to the serve process's /api/secrets; the serve process
| sends a secret_* frame to the machine's bridge and holds the response open
| until secret_result. The value goes in and never comes out.
|
*/

const SECRET_VALUE = 'Zq8-not-a-real-secret-4471';

/** A serve process with bridge key-1 (machine_secrets) and key-old (no machine_secrets). */
function secretRig(float $timeout = 5.0): object
{
    Event::fake();
    config()->set('ai-bridge.machine_secrets.timeout', $timeout);

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
        'machine_secrets' => ['revision' => 1]]));
    $rig->manager->addConnection('key-2', 'conn-2', 'socket-2');
    $rig->handler->handleMessage('conn-2', null, json_encode(['type' => 'hello', 'version' => '0.1', 'providers' => [],
        'machine_secrets' => ['revision' => 1]]));
    $rig->manager->addConnection('key-old', 'conn-old', 'socket-old');
    $rig->handler->handleMessage('conn-old', null, json_encode(['type' => 'hello', 'version' => '0.1', 'providers' => []]));
    $rig->sent = [];

    $rig->from = fn (string $connectionId, array $frame) => $rig->handler->handleMessage($connectionId, null, json_encode($frame));

    return $rig;
}

function secretPost(object $rig, array $body, string $as = 'key-1'): object
{
    $out = new stdClass();
    $out->responses = [];
    $out->raw = '';

    $tcp = Mockery::mock(ConnectionInterface::class);
    $tcp->shouldReceive('write')->andReturnUsing(function (string $raw) use ($out) {
        $out->raw .= $raw;
        [$head, $json] = explode("\r\n\r\n", $raw, 2);
        preg_match('#^HTTP/1\.1 (\d+)#', $head, $m);
        $out->responses[] = ['status' => (int) $m[1], 'body' => json_decode($json, true)];

        return true;
    });
    $tcp->shouldReceive('end');

    $token = app(TokenManager::class)->generate($as, ['scope' => TokenManager::INTERNAL_RELAY_SCOPE], 60);
    $request = new PsrRequest('POST', '/api/secrets', ['Authorization' => "Bearer {$token}"], json_encode($body));
    (new ReflectionMethod(BridgeWebSocketServer::class, 'handleHttpRequest'))->invoke($rig->server, $tcp, $request);

    return $out;
}

describe('POST /api/secrets', function () {
    it('sends secret_put with the value and answers ok without echoing it', function () {
        $rig = secretRig();
        $rig->answer = fn (array $frame) => ($rig->from)('conn-1', ['type' => MessageTypes::SECRET_RESULT, 'id' => $frame['id'], 'ok' => true]);

        $out = secretPost($rig, ['op' => 'put', 'name' => 'mailtrap', 'description' => 'SMTP password', 'value' => SECRET_VALUE]);

        expect($rig->sent)->toHaveCount(1)
            ->and($rig->sent[0])->toMatchArray(['type' => MessageTypes::SECRET_PUT, 'name' => 'mailtrap', 'description' => 'SMTP password', 'value' => SECRET_VALUE])
            ->and($rig->sent[0]['id'])->toStartWith('sec-')
            ->and($out->responses)->toBe([['status' => 200, 'body' => ['ok' => true]]])
            ->and($out->raw)->not->toContain(SECRET_VALUE);
    });

    it('lists names, descriptions and dates, and drops anything else a row carries', function () {
        $rig = secretRig();
        $rig->answer = fn (array $frame) => ($rig->from)('conn-1', ['type' => MessageTypes::SECRET_RESULT, 'id' => $frame['id'], 'ok' => true, 'secrets' => [
            ['name' => 'mailtrap', 'description' => 'SMTP', 'updated_at' => '2026-11-21', 'value' => SECRET_VALUE],
            ['name' => '../etc/passwd'],
            'nonsense',
        ]]);

        $out = secretPost($rig, ['op' => 'list']);

        expect($rig->sent[0])->toMatchArray(['type' => MessageTypes::SECRET_LIST])
            ->and($out->responses[0]['body'])->toBe(['ok' => true, 'secrets' => [['name' => 'mailtrap', 'description' => 'SMTP', 'updated_at' => '2026-11-21']]])
            ->and($out->raw)->not->toContain(SECRET_VALUE);
    });

    it('passes a refusal on with a known code, and makes an unknown one failed', function () {
        $rig = secretRig();
        $rig->answer = fn (array $frame) => ($rig->from)('conn-1', ['type' => MessageTypes::SECRET_RESULT, 'id' => $frame['id'], 'ok' => false, 'code' => 'no_secret_tool', 'error' => 'secret is not installed']);
        $missing = secretPost($rig, ['op' => 'remove', 'name' => 'mailtrap']);

        $rig->answer = fn (array $frame) => ($rig->from)('conn-1', ['type' => MessageTypes::SECRET_RESULT, 'id' => $frame['id'], 'ok' => false, 'code' => 'oops']);
        $odd = secretPost($rig, ['op' => 'describe', 'name' => 'mailtrap', 'description' => 'x']);

        expect($missing->responses[0]['body'])->toBe(['ok' => false, 'code' => 'no_secret_tool', 'error' => 'secret is not installed'])
            ->and($odd->responses[0]['body']['code'])->toBe('failed');
    });

    it('refuses a bad name, a missing value and an unknown op without sending anything', function () {
        $rig = secretRig();

        foreach ([
            ['op' => 'put', 'name' => '../x', 'value' => 'v'],
            ['op' => 'put', 'name' => '.hidden', 'value' => 'v'],
            ['op' => 'put', 'name' => 'ok'],
            ['op' => 'put', 'name' => 'ok', 'value' => ''],
            ['op' => 'describe', 'name' => 'ok'],
            ['op' => 'read', 'name' => 'ok'],
            [],
        ] as $body) {
            $out = secretPost($rig, $body);
            expect($out->responses[0]['status'])->toBe(400)
                ->and($out->responses[0]['body']['code'])->toBe('invalid_request');
        }

        expect($rig->sent)->toBe([]);
    });

    it('refuses a bridge that did not announce machine_secrets, and one not connected', function () {
        $rig = secretRig();

        $old = secretPost($rig, ['op' => 'list'], 'key-old');
        $gone = secretPost($rig, ['op' => 'list'], 'key-nobody');

        expect($old->responses[0]['status'])->toBe(409)
            ->and($old->responses[0]['body']['code'])->toBe('machine_secrets_unsupported')
            ->and($gone->responses[0]['status'])->toBe(404)
            ->and($rig->sent)->toBe([]);
    });

    it('answers 504 timeout when the machine never answers', function () {
        $rig = secretRig(0.01);

        $out = secretPost($rig, ['op' => 'list']);
        $rig->loop->run();

        expect($out->responses)->toHaveCount(1)
            ->and($out->responses[0]['status'])->toBe(504)
            ->and($out->responses[0]['body']['code'])->toBe('timeout');
    });

    it('never lets one machine answer another machine\'s request', function () {
        $rig = secretRig(0.01);
        $out = secretPost($rig, ['op' => 'list']);
        $id = $rig->sent[0]['id'];

        ($rig->from)('conn-2', ['type' => MessageTypes::SECRET_RESULT, 'id' => $id, 'ok' => true, 'secrets' => [['name' => 'planted']]]);
        $rig->loop->run();

        expect($out->responses)->toHaveCount(1)
            ->and($out->responses[0]['status'])->toBe(504)
            ->and(SecretFrames::exchangeKey('key-1', $id))->not->toBe(SecretFrames::exchangeKey('key-2', $id));
    });
});

describe('AiBridge::secrets()', function () {
    function bridgeConnection(): Connection
    {
        return Connection::create(['type' => 'bridge', 'name' => 'Laptop', 'connection_key' => 'key-1']);
    }

    it('posts to the serve process and never logs the value', function () {
        Http::fake(['*/api/secrets' => Http::response(['ok' => true])]);
        Log::spy();
        $connection = bridgeConnection();

        $answer = app(MachineSecrets::class)->put($connection, 'mailtrap', SECRET_VALUE, 'SMTP');

        expect($answer)->toBe(['ok' => true]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/secrets')
            && $request['op'] === 'put' && $request['name'] === 'mailtrap' && $request['value'] === SECRET_VALUE);
        Log::shouldNotHaveReceived('info');
    });

    it('refuses a bad name before anything is sent', function () {
        Http::fake();
        $answer = app(MachineSecrets::class)->put(bridgeConnection(), 'a/b', SECRET_VALUE);

        expect($answer['code'])->toBe('invalid_request');
        Http::assertNothingSent();
    });

    it('turns an unreachable serve process into a code, with no value in the log', function () {
        Http::fake(fn () => throw new RuntimeException('connection refused while posting '.SECRET_VALUE));
        Log::spy();

        $answer = app(MachineSecrets::class)->put(bridgeConnection(), 'mailtrap', SECRET_VALUE);

        expect($answer['code'])->toBe('unreachable');
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => ! str_contains(json_encode($context), SECRET_VALUE));
    });

    it('keeps only safe list rows from the answer', function () {
        Http::fake(['*/api/secrets' => Http::response(['ok' => true, 'secrets' => [['name' => 'a', 'value' => SECRET_VALUE]]])]);

        $answer = app(MachineSecrets::class)->list(bridgeConnection());

        expect($answer)->toBe(['ok' => true, 'secrets' => [['name' => 'a', 'description' => null, 'updated_at' => null]]]);
    });
});
