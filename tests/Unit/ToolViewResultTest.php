<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Contracts\StoresStreamValues;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\Tools\ToolResult;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/*
|--------------------------------------------------------------------------
| Tools with a view (MCP Apps): a result for two readers, joined to the stream
|--------------------------------------------------------------------------
|
| The CLI gets the text in tool_resolve. The server keeps the full result under
| the CLI's own call id (provider_tool_call_id), and the stream's tool_result
| for that call carries it as `ui`, in the stream store and on the recorded
| message block. Driven end to end through the MessageHandler of the serve
| process, as a relayed turn.
|
*/

uses(RefreshDatabase::class);

const VIEW_URI = 'ui://studio/grant-request';

function viewRig(): object
{
    Event::fake();

    $registry = new ToolRegistry();
    $registry->register(
        'show_grant_request',
        'Show the person a grant request to confirm',
        ['type' => 'object', 'properties' => ['grant_id' => ['type' => 'integer', 'description' => 'The grant request']]],
        fn (array $p) => ToolResult::withView(
            text: "Grant request {$p['grant_id']} is shown to the person.",
            structured: ['grant_id' => $p['grant_id'], 'gates' => ['walkthrough']],
        ),
        view: VIEW_URI,
    );
    $registry->register('show_plain_card', 'A view tool returning a plain array', [], fn () => ['ok' => true], view: 'ui://studio/plain');
    $registry->register('no_view', 'A tool without a view', [], fn () => ToolResult::withView('just text', ['ignored' => true]));

    $rig = new stdClass();
    $rig->manager = new BridgeConnectionManager();
    $rig->manager->addConnection('user-1', 'conn-1');
    $rig->handler = new MessageHandler($rig->manager, app(TokenManager::class), $registry);
    $rig->conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    $rig->handler->registerRelayedRequest('req-v', 'user-1', (string) $rig->conversation->id);
    $rig->store = app(StreamStoreContract::class);

    $rig->send = fn (array $frame) => $rig->handler->handleMessage('conn-1', null, json_encode($frame));
    $rig->stream = fn (string $event, array $data) => ($rig->send)(['type' => MessageTypes::STREAM, 'request_id' => 'req-v', 'event' => $event, 'data' => $data]);

    return $rig;
}

/** The CLI's stream block for a server tool, as it precedes the tool_call frame. */
function streamToolBlock(object $rig, int $index, string $toolName, string $toolUseId, string $arguments = ''): void
{
    ($rig->stream)(MessageTypes::BLOCK_START, ['block_index' => $index, 'block_type' => 'tool_call', 'tool_name' => $toolName, 'tool_call_id' => $toolUseId]);
    if ($arguments !== '') {
        ($rig->stream)(MessageTypes::BLOCK_DELTA, ['block_index' => $index, 'content' => $arguments]);
    }
    ($rig->stream)(MessageTypes::BLOCK_STOP, ['block_index' => $index]);
}

function toolCallFrame(object $rig, string $toolName, array $arguments, string $callId, ?string $providerId): ?array
{
    $frame = ['type' => MessageTypes::TOOL_CALL, 'request_id' => 'req-v', 'tool_call_id' => $callId, 'tool_name' => $toolName, 'arguments' => $arguments];
    if ($providerId !== null) {
        $frame['provider_tool_call_id'] = $providerId;
    }

    return ($rig->send)($frame);
}

/** @return list<array<string, mixed>> the buffered tool_result events' data */
function bufferedToolResults(object $rig): array
{
    return array_values(array_map(
        fn (array $e) => $e['data'],
        array_filter($rig->store->range('req-v'), fn (array $e) => $e['event'] === MessageTypes::TOOL_RESULT),
    ));
}

function recordedBlocks(object $rig): array
{
    return $rig->conversation->messages()->where('role', 'assistant')->latest('id')->first()?->blocks ?? [];
}

it('sends the CLI only the text, and keeps the full result under the CLI call id', function () {
    $rig = viewRig();

    $reply = toolCallFrame($rig, 'show_grant_request', ['grant_id' => 42], 'tc_7', 'toolu_01');

    expect($reply['type'])->toBe(MessageTypes::TOOL_RESOLVE)
        ->and($reply['tool_call_id'])->toBe('tc_7')
        ->and($reply['result'])->toBe('Grant request 42 is shown to the person.')
        ->and($rig->store)->toBeInstanceOf(StoresStreamValues::class)
        ->and($rig->store->getValue('req-v', StreamHandler::toolViewKey('toolu_01')))->toBe([
            'resource_uri' => VIEW_URI,
            'tool_name' => 'show_grant_request',
            'arguments' => ['grant_id' => 42],
            'result' => [
                'content' => [['type' => 'text', 'text' => 'Grant request 42 is shown to the person.']],
                'structuredContent' => ['grant_id' => 42, 'gates' => ['walkthrough']],
            ],
        ]);
});

it('attaches ui to the matching tool_result only, buffered and recorded', function () {
    $rig = viewRig();

    streamToolBlock($rig, 0, 'mcp__bridge__show_grant_request', 'toolu_01', '{"grant_id":42}');
    toolCallFrame($rig, 'show_grant_request', ['grant_id' => 42], 'tc_7', 'toolu_01');
    ($rig->stream)(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_01', 'result' => 'Grant request 42 is shown to the person.']);
    // A tool that ran on the machine: no frame, no view.
    streamToolBlock($rig, 1, 'Bash', 'toolu_02', '{"command":"ls"}');
    ($rig->stream)(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_02', 'result' => 'README.md']);
    ($rig->stream)(MessageTypes::DONE, ['usage' => null]);

    [$viewed, $local] = bufferedToolResults($rig);
    expect($viewed['ui']['resource_uri'])->toBe(VIEW_URI)
        ->and($viewed['ui']['tool_name'])->toBe('show_grant_request')
        ->and($viewed['ui']['arguments'])->toBe(['grant_id' => 42])
        ->and($viewed['ui']['result']['structuredContent'])->toBe(['grant_id' => 42, 'gates' => ['walkthrough']])
        ->and($local)->not->toHaveKey('ui');

    $calls = collect(recordedBlocks($rig))->where('type', 'tool_call')->keyBy('tool_call_id');
    expect($calls)->toHaveCount(2)
        ->and($calls['toolu_01']['ui'])->toBe($viewed['ui'])
        ->and($calls['toolu_01']['result'])->toBe('Grant request 42 is shown to the person.')
        ->and($calls['toolu_02'])->not->toHaveKey('ui')
        ->and($calls['toolu_01'])->not->toHaveKey('_provider_tool_call_id');
});

it('gives a call without provider_tool_call_id no view, and keeps nothing', function () {
    $rig = viewRig();

    streamToolBlock($rig, 0, 'mcp__bridge__show_grant_request', 'toolu_01', '{"grant_id":42}');
    $reply = toolCallFrame($rig, 'show_grant_request', ['grant_id' => 42], 'tc_7', null);
    ($rig->stream)(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_01', 'result' => $reply['result']]);
    ($rig->stream)(MessageTypes::DONE, ['usage' => null]);

    expect($reply['result'])->toBe('Grant request 42 is shown to the person.')
        ->and($rig->store->getValue('req-v', StreamHandler::toolViewKey('toolu_01')))->toBeNull()
        ->and(bufferedToolResults($rig)[0])->not->toHaveKey('ui')
        ->and(collect(recordedBlocks($rig))->firstWhere('type', 'tool_call'))->not->toHaveKey('ui');
});

it('keeps no view for a tool without one, even when it returns a ToolResult', function () {
    $rig = viewRig();

    $reply = toolCallFrame($rig, 'no_view', [], 'tc_1', 'toolu_09');

    expect($reply['result'])->toBe('just text')
        ->and($rig->store->getValue('req-v', StreamHandler::toolViewKey('toolu_09')))->toBeNull();
});

it('turns a plain return value of a view tool into text content without structuredContent', function () {
    $rig = viewRig();

    $reply = toolCallFrame($rig, 'show_plain_card', [], 'tc_2', 'toolu_03');

    expect($reply['result'])->toBe(['ok' => true])
        ->and($rig->store->getValue('req-v', StreamHandler::toolViewKey('toolu_03'))['result'])->toBe([
            'content' => [['type' => 'text', 'text' => '{"ok":true}']],
        ]);
});

it('pairs each frame with its own stream block by provider_tool_call_id, not by name', function () {
    $rig = viewRig();

    // Two parallel calls of one tool whose arguments never reached the stream,
    // and whose frames come back in the opposite order. By name this is
    // ambiguous and nothing is copied; by id each block gets its own arguments.
    streamToolBlock($rig, 0, 'mcp__bridge__show_grant_request', 'toolu_A');
    streamToolBlock($rig, 1, 'mcp__bridge__show_grant_request', 'toolu_B');
    toolCallFrame($rig, 'show_grant_request', ['grant_id' => 2], 'tc_2', 'toolu_B');
    toolCallFrame($rig, 'show_grant_request', ['grant_id' => 1], 'tc_1', 'toolu_A');
    ($rig->stream)(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_A', 'result' => 'one']);
    ($rig->stream)(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_B', 'result' => 'two']);
    ($rig->stream)(MessageTypes::DONE, ['usage' => null]);

    $calls = collect(recordedBlocks($rig))->where('type', 'tool_call')->keyBy('tool_call_id');

    expect($calls)->toHaveCount(2)
        ->and($calls['toolu_A']['parameters'])->toBe(['grant_id' => 1])
        ->and($calls['toolu_B']['parameters'])->toBe(['grant_id' => 2])
        ->and($calls['toolu_A']['ui']['arguments'])->toBe(['grant_id' => 1])
        ->and($calls['toolu_B']['ui']['arguments'])->toBe(['grant_id' => 2]);
});
