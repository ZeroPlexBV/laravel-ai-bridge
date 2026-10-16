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
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\Tools\ToolResult;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| tool_result.ui from the bridge: an upstream tool's view
|--------------------------------------------------------------------------
|
| An upstream tool runs on the machine and never produces a tool_call frame;
| the bridge puts its view on the first tool_result. The serve process keeps it
| on the buffered event and the recorded block under the same size rules as a
| server-built view, stamps the connection that streamed it, and never lets the
| server-built path overwrite it.
|
*/

function upstreamViewRig(): object
{
    Event::fake();

    $registry = new ToolRegistry();
    $registry->register('show_grant_request', 'A server tool with a view', [], fn () => ToolResult::withView('Shown.', ['grant_id' => 1]), view: 'ui://studio/grant');

    $rig = new stdClass();
    $rig->connection = Connection::create(['type' => Connection::TYPE_BRIDGE, 'connection_key' => 'key-1', 'name' => 'laptop']);
    $rig->manager = new BridgeConnectionManager();
    $rig->manager->addConnection('key-1', 'conn-1');
    $rig->handler = new MessageHandler($rig->manager, app(TokenManager::class), $registry);
    $rig->conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    $rig->handler->registerRelayedRequest('req-u', 'key-1', (string) $rig->conversation->id);
    $rig->store = app(StreamStoreContract::class);
    $rig->send = fn (array $frame) => $rig->handler->handleMessage('conn-1', null, json_encode($frame));
    $rig->stream = fn (string $event, array $data) => ($rig->send)(['type' => MessageTypes::STREAM, 'request_id' => 'req-u', 'event' => $event, 'data' => $data]);

    return $rig;
}

function upstreamUi(array $over = []): array
{
    return $over + [
        'server' => 'weather',
        'tool_name' => 'show_forecast',
        'resource_uri' => 'ui://weather/view.html',
        'arguments' => ['city' => 'Utrecht'],
        'result' => ['content' => [['type' => 'text', 'text' => 'Shown 3']], 'structuredContent' => ['days' => 3]],
    ];
}

function upstreamBlock(object $rig, int $index, string $id): void
{
    ($rig->stream)(MessageTypes::BLOCK_START, ['block_index' => $index, 'block_type' => 'tool_call', 'tool_name' => 'weather__show_forecast', 'tool_call_id' => $id]);
    ($rig->stream)(MessageTypes::BLOCK_STOP, ['block_index' => $index]);
}

function upstreamResults(object $rig): array
{
    return array_values(array_map(fn ($e) => $e['data'], array_filter($rig->store->range('req-u'), fn ($e) => $e['event'] === MessageTypes::TOOL_RESULT)));
}

function upstreamRecorded(object $rig): array
{
    return collect($rig->conversation->messages()->where('role', 'assistant')->latest('id')->first()?->blocks ?? [])
        ->where('type', 'tool_call')->keyBy('tool_call_id')->all();
}

it('keeps the view on the buffered event and the recorded block, with the connection that streamed it', function () {
    $rig = upstreamViewRig();

    upstreamBlock($rig, 0, 'toolu_u1');
    // A bridge-supplied connection_id is never trusted.
    ($rig->stream)(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_u1', 'result' => 'Shown 3', 'ui' => upstreamUi(['connection_id' => 999, 'extra' => 'dropped'])]);
    ($rig->stream)(MessageTypes::DONE, ['usage' => null]);

    $expected = upstreamUi() + ['connection_id' => $rig->connection->id];
    expect(upstreamResults($rig)[0]['ui'])->toBe($expected)
        ->and(upstreamRecorded($rig)['toolu_u1']['ui'])->toBe($expected);
});

it('hands the view to onToolResult as its fifth argument, like a server-built one', function () {
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('markCompleted')->byDefault();
    $handler = new StreamHandler($provider, 'req-x');
    $handler->setMode(ProviderMode::Bridge);
    $seen = null;
    $handler->onToolResult(function (string $id, mixed $result, ?bool $isError, ?string $parent, ?array $ui) use (&$seen) {
        $seen = $ui;
    });

    $handler->dispatchEvent(new StreamEvent('req-x', MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_u1', 'result' => 'ok', 'ui' => upstreamUi()]));

    expect($seen)->toBe(upstreamUi() + ['connection_id' => null]);
});

it('takes the view from the first chunk of a chunked result only', function () {
    $rig = upstreamViewRig();

    upstreamBlock($rig, 0, 'toolu_u1');
    ($rig->stream)(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_u1', 'result' => 'Shown ', 'chunk_index' => 0, 'ui' => upstreamUi()]);
    ($rig->stream)(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_u1', 'result' => '3', 'chunk_index' => 1, 'final' => true, 'ui' => upstreamUi(['server' => 'impostor'])]);
    ($rig->stream)(MessageTypes::DONE, ['usage' => null]);

    $result = upstreamResults($rig)[0];
    expect($result['result'])->toBe('Shown 3')
        ->and($result['ui']['server'])->toBe('weather');
});

it('drops a view over the size a host will draw, and keeps the result', function () {
    $rig = upstreamViewRig();

    upstreamBlock($rig, 0, 'toolu_u1');
    ($rig->stream)(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_u1', 'result' => 'big', 'ui' => upstreamUi(['result' => ['content' => [], 'structuredContent' => ['blob' => str_repeat('x', StreamHandler::MAX_TOOL_VIEW_BYTES)]]])]);
    ($rig->stream)(MessageTypes::DONE, ['usage' => null]);

    expect(upstreamResults($rig)[0])->not->toHaveKey('ui')
        ->and(upstreamResults($rig)[0]['result'])->toBe('big')
        ->and(upstreamRecorded($rig)['toolu_u1'])->not->toHaveKey('ui');
});

it('drops a ui that is not an upstream view', function (array $ui) {
    $rig = upstreamViewRig();

    upstreamBlock($rig, 0, 'toolu_u1');
    ($rig->stream)(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_u1', 'result' => 'x', 'ui' => $ui]);

    expect(upstreamResults($rig)[0])->not->toHaveKey('ui');
})->with([
    'no server' => [upstreamUi(['server' => ''])],
    'not ui://' => [upstreamUi(['resource_uri' => 'https://evil.example/x.html'])],
    'result not an object' => [upstreamUi(['result' => 'text'])],
    'no tool name' => [upstreamUi(['tool_name' => null])],
]);

it('lets a bridge-sent view win over the server-built path for the same call id', function () {
    $rig = upstreamViewRig();

    upstreamBlock($rig, 0, 'toolu_s1');
    ($rig->send)(['type' => MessageTypes::TOOL_CALL, 'request_id' => 'req-u', 'tool_call_id' => 'tc_1', 'tool_name' => 'show_grant_request', 'arguments' => [], 'provider_tool_call_id' => 'toolu_s1']);
    ($rig->stream)(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_s1', 'result' => 'Shown.', 'ui' => upstreamUi()]);

    expect(upstreamResults($rig)[0]['ui']['server'])->toBe('weather');
});

it('still attaches a server-built view, without server or connection_id, when the bridge sends none', function () {
    $rig = upstreamViewRig();

    upstreamBlock($rig, 0, 'toolu_s1');
    ($rig->send)(['type' => MessageTypes::TOOL_CALL, 'request_id' => 'req-u', 'tool_call_id' => 'tc_1', 'tool_name' => 'show_grant_request', 'arguments' => [], 'provider_tool_call_id' => 'toolu_s1']);
    ($rig->stream)(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_s1', 'result' => 'Shown.']);
    ($rig->stream)(MessageTypes::DONE, ['usage' => null]);

    $ui = upstreamResults($rig)[0]['ui'];
    expect($ui['resource_uri'])->toBe('ui://studio/grant')
        ->and($ui['result']['structuredContent'])->toBe(['grant_id' => 1])
        ->and($ui)->not->toHaveKey('server')
        ->and($ui)->not->toHaveKey('connection_id')
        ->and(upstreamRecorded($rig)['toolu_s1']['ui'])->toBe($ui);
});
