<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Events\McpRequestRelayed;
use Tetrix\AiBridge\Facades\AiBridge;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Upstreams\McpRequestFailed;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AiBridge::mcpRequest() — a view's request, from a web worker
|--------------------------------------------------------------------------
|
| The worker reaches the serve process's /api/mcp with a relay token for the
| connection (faked here; the serve side is UpstreamServeApiTest), gets the
| upstream's result back unchanged, or a McpRequestFailed carrying the code.
|
*/

beforeEach(function () {
    $this->connection = Connection::create(['type' => Connection::TYPE_BRIDGE, 'connection_key' => 'key-1', 'name' => 'laptop']);
});

it('posts to the serve process as the connection and returns the result unchanged', function () {
    Event::fake([McpRequestRelayed::class]);
    $result = ['contents' => [['uri' => 'ui://weather/view.html', 'mimeType' => 'text/html;profile=mcp-app', 'text' => '<!doctype html>']]];
    Http::fake(['*/api/mcp' => Http::response(['ok' => true, 'result' => $result])]);

    expect(AiBridge::mcpRequest($this->connection, 'weather', 'resources/read', ['uri' => 'ui://weather/view.html']))->toBe($result);

    Http::assertSent(function (HttpRequest $r) {
        $claims = json_decode(base64_decode(strtr(explode('.', $r->header('Authorization')[0])[1], '-_', '+/')), true);
        $sub = $claims['sub'] ?? null;

        return str_ends_with($r->url(), '/api/mcp')
            && $sub === 'key-1' && ($claims['scope'] ?? null) === TokenManager::INTERNAL_RELAY_SCOPE
            && json_decode($r->body(), true) === ['server' => 'weather', 'method' => 'resources/read', 'params' => ['uri' => 'ui://weather/view.html']];
    });
    Event::assertDispatched(McpRequestRelayed::class, fn (McpRequestRelayed $e) => $e->connectionId === $this->connection->id
        && $e->server === 'weather' && $e->method === 'resources/read' && $e->target === 'ui://weather/view.html' && $e->outcome === 'ok');
});

it('throws with the bridge\'s code, and audits the outcome', function () {
    Event::fake([McpRequestRelayed::class]);
    Http::fake(['*/api/mcp' => Http::response(['ok' => false, 'code' => 'refused', 'error' => 'refresh is not callable from a view'])]);

    try {
        AiBridge::upstreams()->mcpRequest($this->connection, 'weather', 'tools/call', ['name' => 'refresh', 'arguments' => ['city' => 'Utrecht']]);
        $this->fail('expected McpRequestFailed');
    } catch (McpRequestFailed $e) {
        expect($e->errorCode)->toBe('refused')->and($e->getMessage())->toBe('refresh is not callable from a view');
    }

    Event::assertDispatched(McpRequestRelayed::class, fn (McpRequestRelayed $e) => $e->target === 'refresh' && $e->outcome === 'refused');
});

it('maps the serve process\'s timeout, missing bridge and old bridge to codes', function (int $status, array $body, string $code) {
    Http::fake(['*/api/mcp' => Http::response($body, $status)]);

    expect(fn () => AiBridge::mcpRequest($this->connection, 'weather', 'tools/call', ['name' => 'refresh']))
        ->toThrow(fn (McpRequestFailed $e) => expect($e->errorCode)->toBe($code));
})->with([
    'timeout' => [504, ['ok' => false, 'code' => 'timeout', 'error' => 'The bridge did not answer in time.'], 'timeout'],
    'not connected' => [404, ['ok' => false, 'code' => 'not_connected', 'error' => 'x'], 'not_connected'],
    'no mcp_apps' => [409, ['ok' => false, 'code' => 'mcp_apps_unsupported', 'error' => 'x'], 'mcp_apps_unsupported'],
    'garbage' => [500, [], 'unreachable'],
]);

it('waits the configured timeout plus a margin, and says unreachable when it cannot connect', function () {
    config()->set('ai-bridge.server.mcp_request_timeout', 30);
    Http::fake(fn () => throw new ConnectionException('refused'));

    expect(fn () => AiBridge::mcpRequest($this->connection, 'weather', 'tools/call', ['name' => 'refresh']))
        ->toThrow(fn (McpRequestFailed $e) => expect($e->errorCode)->toBe('unreachable'));
});

it('refuses other methods and non-ui reads locally, sending nothing', function (string $method, array $params, string $code) {
    Event::fake([McpRequestRelayed::class]);
    Http::fake();

    expect(fn () => AiBridge::mcpRequest($this->connection, 'weather', $method, $params))
        ->toThrow(fn (McpRequestFailed $e) => expect($e->errorCode)->toBe($code));

    Http::assertNothingSent();
    Event::assertDispatched(McpRequestRelayed::class, fn (McpRequestRelayed $e) => $e->outcome === $code);
})->with([
    'tools/list' => ['tools/list', [], 'unsupported'],
    'resources/list' => ['resources/list', [], 'unsupported'],
    'prompts/get' => ['prompts/get', ['name' => 'x'], 'unsupported'],
    'https read' => ['resources/read', ['uri' => 'https://example.com/x'], 'refused'],
    'no tool name' => ['tools/call', ['arguments' => []], 'invalid_request'],
]);

it('refuses a BYOK connection, which has no upstreams', function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    Http::fake();
    $byok = Connection::create(['type' => Connection::TYPE_BYOK, 'endpoint' => 'https://api.example', 'api_key' => 'k']);

    expect(fn () => AiBridge::mcpRequest($byok, 'weather', 'tools/call', ['name' => 'refresh']))
        ->toThrow(fn (McpRequestFailed $e) => expect($e->errorCode)->toBe('invalid_request'));
    Http::assertNothingSent();
});
