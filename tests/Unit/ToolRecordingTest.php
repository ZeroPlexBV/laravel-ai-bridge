<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\ConversationRecorder;
use Tetrix\AiBridge\Streaming\StreamHandler;

/*
|--------------------------------------------------------------------------
| What a recorded turn remembers about its tool calls
|--------------------------------------------------------------------------
|
| A tool that ran on the operator's own machine — Bash, Read, an editor — has
| no WebSocket tool_call frame; the stream block is the only record of it that
| will ever exist. The recorder used to drop those blocks unconditionally, so a
| recorded turn showed the assistant's prose and no sign that anything ran.
|
*/

uses(RefreshDatabase::class);

/** A handler recording into a conversation, with the provider stubbed out. */
function recordingHandler(): StreamHandler
{
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $handler = new StreamHandler($provider);
    $handler->setMode(ProviderMode::Byok);

    return $handler;
}

/** Build the stream envelope the bridge actually sends for one event. */
function wire(string $event, array $data): StreamEvent
{
    return StreamEvent::fromArray([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-rec',
        'event' => $event,
        'data' => $data,
    ]);
}

/** Drive a whole turn and return the blocks it persisted. */
function recordTurn(callable $drive): array
{
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    $handler = recordingHandler();
    $handler->setConversationId((string) $conversation->id);
    ConversationRecorder::attach($handler, $conversation);

    $drive($handler);

    $message = $conversation->messages()->where('role', 'assistant')->latest('id')->first();

    return $message?->blocks ?? [];
}

test('a locally-run tool is recorded with its name and arguments', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call',
            'tool_name' => 'Bash', 'tool_call_id' => 'toolu_1',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, [
            'block_index' => 0, 'content' => '{"command":"echo hello"}',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    expect($tool)->not->toBeNull()
        ->and($tool['tool_name'])->toBe('Bash')
        ->and($tool['tool_call_id'])->toBe('toolu_1')
        ->and($tool['parameters'])->toBe(['command' => 'echo hello']);
});

test('a tool the server resolves is recorded once, not twice', function () {
    // It arrives both as a stream block and as the canonical tool_call frame.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call',
            'tool_name' => 'mcp__bridge__company_directory', 'tool_call_id' => 'toolu_2',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"name":"Jasper"}']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchToolCall('company_directory', ['name' => 'Jasper'], 'mcp-req-1');
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tools = collect($blocks)->where('type', 'tool_call')->values();

    // One block, and it is the STREAM one: it carries the CLI's own tool_call_id,
    // which is what tool_result events are keyed by, and its own arguments — so
    // nothing is copied between calls and nothing can be mis-attributed. The
    // name stays as the CLI reported it; display formatting is the consumer's.
    expect($tools)->toHaveCount(1)
        ->and($tools[0]['tool_name'])->toBe('mcp__bridge__company_directory')
        ->and($tools[0]['tool_call_id'])->toBe('toolu_2')
        ->and($tools[0]['parameters'])->toBe(['name' => 'Jasper']);
});

test('a result still finds a server-resolved call after reconciliation', function () {
    // The id kept must be the one results are keyed by. Replacing it with the
    // frame's mcp-<rid>-<n> orphaned every result — the opposite of the fix it
    // was written as.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call',
            'tool_name' => 'mcp__bridge__roll_dice', 'tool_call_id' => 'toolu_9',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"n":1}']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchToolCall('roll_dice', ['n' => 1], 'mcp-req-1');
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_9', 'result' => '17']));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $call = collect($blocks)->firstWhere('type', 'tool_call');

    // Attached to the call, which is only possible because the id kept is the
    // one results are keyed by.
    expect($call['result'])->toBe('17')
        ->and(collect($blocks)->where('type', 'tool_result'))->toHaveCount(0);
});

test('parallel calls to one tool do not swap arguments when frames return out of order', function () {
    // Positional matching copied the wrong frame's arguments onto a block. This
    // file rejects exactly that reasoning about tool_results a few lines away.
    $blocks = recordTurn(function (StreamHandler $h) {
        foreach ([['toolu_A', '{"n":"A"}'], ['toolu_B', '{"n":"B"}']] as $i => [$id, $args]) {
            $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
                'block_index' => $i, 'block_type' => 'tool_call',
                'tool_name' => 'mcp__bridge__roll_dice', 'tool_call_id' => $id,
            ]));
            $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => $i, 'content' => $args]));
            $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => $i]));
        }
        // Frames come back reversed.
        $h->dispatchToolCall('roll_dice', ['n' => 'B'], 'mcp-B');
        $h->dispatchToolCall('roll_dice', ['n' => 'A'], 'mcp-A');
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $calls = collect($blocks)->where('type', 'tool_call')->values();

    expect($calls)->toHaveCount(2)
        ->and($calls[0]['tool_call_id'])->toBe('toolu_A')
        ->and($calls[0]['parameters'])->toBe(['n' => 'A'])
        ->and($calls[1]['tool_call_id'])->toBe('toolu_B')
        ->and($calls[1]['parameters'])->toBe(['n' => 'B']);
});

test('a server call with no stream block of its own is still recorded', function () {
    // Where the match precision actually bites: a loose suffix rule lets a
    // LOCAL namespaced block absorb the frame, and the server call — which had
    // no block of its own — disappears from the record entirely.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call',
            'tool_name' => 'mcp__playwright__navigate', 'tool_call_id' => 'toolu_local',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"url":"LOCAL"}']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));

        // A server-resolved call the provider reported no block for.
        $h->dispatchToolCall('navigate', ['url' => 'SERVER'], 'mcp-1');
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $calls = collect($blocks)->where('type', 'tool_call')->values();

    expect($calls)->toHaveCount(2)
        ->and($calls[0]['parameters'])->toBe(['url' => 'LOCAL'])
        ->and($calls[1]['tool_name'])->toBe('navigate')
        ->and($calls[1]['parameters'])->toBe(['url' => 'SERVER']);
});

test('an operator\'s own MCP tool is not claimed by a bridge frame', function () {
    // In native posture the operator's own MCP servers are in play. An
    // open-ended `__` suffix match let a bridge frame claim one of those calls
    // and destroy its only record.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call',
            'tool_name' => 'mcp__playwright__navigate', 'tool_call_id' => 'toolu_local',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"url":"LOCAL"}']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));

        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 1, 'block_type' => 'tool_call',
            'tool_name' => 'mcp__bridge__navigate', 'tool_call_id' => 'toolu_srv',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 1, 'content' => '{"url":"SERVER"}']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 1]));

        $h->dispatchToolCall('navigate', ['url' => 'SERVER'], 'mcp-1');
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $calls = collect($blocks)->where('type', 'tool_call')->values();

    expect($calls)->toHaveCount(2)
        ->and($calls[0]['tool_name'])->toBe('mcp__playwright__navigate')
        ->and($calls[0]['parameters'])->toBe(['url' => 'LOCAL'])
        ->and($calls[1]['tool_name'])->toBe('mcp__bridge__navigate');
});

test('a frame arriving before the block closes still records one call', function () {
    // Reconciling on arrival made this order-sensitive, and PROTOCOL.md does
    // not pin the order down.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call',
            'tool_name' => 'mcp__bridge__roll_dice', 'tool_call_id' => 'toolu_1',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"n":1}']));
        $h->dispatchToolCall('roll_dice', ['n' => 1], 'mcp-1');
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect(collect($blocks)->where('type', 'tool_call'))->toHaveCount(1);
});

test('a block the stream never closed is not lost when the next one opens', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Bash',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"command":"ls"}']));
        // no block_stop
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, ['block_index' => 1, 'block_type' => 'text']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 1, 'content' => 'done!']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 1]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $call = collect($blocks)->firstWhere('type', 'tool_call');

    expect($call)->not->toBeNull()
        ->and($call['tool_name'])->toBe('Bash')
        ->and($call['parameters'])->toBe(['command' => 'ls']);
});

test('a multi-byte argument payload does not destroy the whole turn', function () {
    // substr cuts at a byte offset, so a character straddling the boundary
    // leaves invalid UTF-8 — the blocks cast then fails and the ENTIRE
    // assistant message is lost, prose and all.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Write',
        ]));
        // 'é' is two bytes. The single 'x' shifts the alignment so that byte
        // 65536 lands in the MIDDLE of a character — without it the cut happens
        // to fall on a boundary and substr produces valid UTF-8 by luck, which
        // is how the first version of this test passed against the bug.
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, [
            'block_index' => 0, 'content' => '{"content":"x'.str_repeat('é', 40000).'"}',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, ['block_index' => 1, 'block_type' => 'text']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 1, 'content' => 'the prose survived']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 1]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect($blocks)->not->toBeEmpty();
    expect(collect($blocks)->firstWhere('type', 'text')['text'])->toBe('the prose survived');
    expect(mb_check_encoding(collect($blocks)->firstWhere('type', 'tool_call')['parameters_raw'], 'UTF-8'))->toBeTrue();
});

test('a tool result is recorded with its failure status', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 'toolu_1', 'result' => 'boom', 'is_error' => true,
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $result = collect($blocks)->firstWhere('type', 'tool_result');

    expect($result['result'])->toBe('boom')
        ->and($result['is_error'])->toBeTrue();
});

test('a result with no reported status records no status at all', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, ['tool_call_id' => 't', 'result' => 'ok']));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect(collect($blocks)->firstWhere('type', 'tool_result'))->not->toHaveKey('is_error');
});

test('arguments cut off mid-stream are kept raw rather than reported as none', function () {
    // "the arguments were truncated" and "the tool was called with none" are
    // different things and a reader should be able to tell them apart.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Read',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"file_pa']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    // Kept as a sibling key, not inside parameters: a tool may genuinely take
    // an argument called `_raw`, and a sentinel that can appear in the data is
    // the same mistake as reading failure out of an "Error:" prefix.
    expect($tool['parameters'])->toBe([])
        ->and($tool['parameters_raw'])->toBe('{"file_pa');
});

test('a tool the bridge runs locally on the server\'s behalf is kept', function () {
    // A server-declared tool with execute: "local" runs ON the bridge and never
    // emits a tool_call frame, yet it still reaches the model namespaced under
    // mcp__bridge__. Identifying the shadow by that prefix deleted exactly these
    // calls and left their results orphaned.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call',
            'tool_name' => 'mcp__bridge__fetch_mail', 'tool_call_id' => 'toolu_local',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"box":"inbox"}']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        // No tool_call frame — the bridge ran it and returned the result itself.
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_local', 'result' => '12 messages']));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    expect($tool)->not->toBeNull()
        ->and($tool['tool_name'])->toBe('mcp__bridge__fetch_mail')
        ->and($tool['parameters'])->toBe(['box' => 'inbox']);
});

test('arguments survive a turn that dies before the block closes', function () {
    // A truncated turn never sends block_stop — that is what truncated means —
    // so decoding only there left a reader shown "called with no arguments".
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Bash',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"command":"echo hi"}']));
        // no block_stop
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    expect($tool['parameters'])->toBe(['command' => 'echo hi'])
        ->and($tool)->not->toHaveKey('text');
});

test('a real argument called _raw is not mistaken for truncation', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Custom',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, [
            'block_index' => 0, 'content' => '{"_raw":"i am a real argument"}',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    expect($tool['parameters'])->toBe(['_raw' => 'i am a real argument'])
        ->and($tool)->not->toHaveKey('parameters_raw');
});

test('valid JSON that is not an argument object keeps its text', function () {
    foreach (['[1,2,3]', 'null', '123', '"hello"'] as $payload) {
        $blocks = recordTurn(function (StreamHandler $h) use ($payload) {
            $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
                'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Odd',
            ]));
            $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => $payload]));
            $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
            $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
        });

        $tool = collect($blocks)->firstWhere('type', 'tool_call');

        expect($tool['parameters'])->toBe([])
            ->and($tool['parameters_raw'])->toBe($payload);
    }
});

test('enormous arguments are truncated, and say so', function () {
    // A Write call's arguments are a whole file. Recording these is a new
    // growth path in the messages table; silent truncation would be worse.
    $body = str_repeat('x', 80000);

    $blocks = recordTurn(function (StreamHandler $h) use ($body) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Write',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, [
            'block_index' => 0, 'content' => '{"content":"'.$body.'"}',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    expect(strlen($tool['parameters_raw']))->toBe(65536)
        ->and($tool['parameters_truncated_bytes'])->toBeGreaterThan(65536);
});

test('internal reconciliation bookkeeping does not reach a consumer', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Bash',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{}']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect(collect($blocks)->firstWhere('type', 'tool_call'))->not->toHaveKey('_from_stream');
});

test('a frame supplies the arguments when the block could not parse its own', function () {
    // The block is usually richer — it has the id results are keyed by — but
    // its arguments are raw delta text and can arrive truncated or unparsed,
    // where the frame carries them already parsed. Dropping the frame outright
    // then persisted a call with no usable arguments at all.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call',
            'tool_name' => 'mcp__bridge__roll_dice', 'tool_call_id' => 'toolu_1',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"notation":"1d2']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchToolCall('roll_dice', ['notation' => '1d20+5'], 'mcp-1');
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $call = collect($blocks)->firstWhere('type', 'tool_call');

    expect($call['parameters'])->toBe(['notation' => '1d20+5'])
        // …and it keeps the id a tool_result is matched by.
        ->and($call['tool_call_id'])->toBe('toolu_1');
});

test('a block with no deltas at all takes the frame\'s arguments', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call',
            'tool_name' => 'mcp__bridge__roll_dice', 'tool_call_id' => 'toolu_1',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchToolCall('roll_dice', ['notation' => '1d20'], 'mcp-1');
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect(collect($blocks)->firstWhere('type', 'tool_call')['parameters'])->toBe(['notation' => '1d20']);
});

test('arguments are not copied when two parallel calls make the pairing ambiguous', function () {
    // With two calls to one tool there is no way to tell which frame belongs to
    // which block. Where it is ambiguous nothing moves, and each block keeps
    // what it has — the same reasoning this file applies to tool_results.
    $blocks = recordTurn(function (StreamHandler $h) {
        foreach ([['toolu_A', '{"n":"trunc'], ['toolu_B', '{"n":"B"}']] as $i => [$id, $args]) {
            $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
                'block_index' => $i, 'block_type' => 'tool_call',
                'tool_name' => 'mcp__bridge__roll_dice', 'tool_call_id' => $id,
            ]));
            $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => $i, 'content' => $args]));
            $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => $i]));
        }
        $h->dispatchToolCall('roll_dice', ['n' => 'A'], 'mcp-A');
        $h->dispatchToolCall('roll_dice', ['n' => 'B'], 'mcp-B');
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $calls = collect($blocks)->where('type', 'tool_call')->values();

    expect($calls[0]['parameters_raw'])->toBe('{"n":"trunc')
        ->and($calls[1]['parameters'])->toBe(['n' => 'B']);
});

test('a nameless unclosed tool block does not swallow the next block', function () {
    // The tool-call suppression flag was never reset on the text path, so the
    // assistant's prose was consumed by a block that had already been dropped.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, ['block_index' => 0, 'block_type' => 'tool_call']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{}']));
        // no block_stop
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, ['block_index' => 1, 'block_type' => 'text']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 1, 'content' => 'Here is my answer.']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 1]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect(collect($blocks)->pluck('text')->filter()->values()->all())->toBe(['Here is my answer.']);
});

test('a frame carrying enormous arguments is capped like a block is', function () {
    // In BYOK and Managed modes every tool call is a frame and no stream block
    // is ever emitted, so a cap that only covered blocks was absent entirely
    // there.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchToolCall('write_file', ['content' => str_repeat('x', 200000)], 'mcp-1');
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $call = collect($blocks)->firstWhere('type', 'tool_call');

    expect($call['parameters'])->toBe([])
        ->and(strlen($call['parameters_raw']))->toBeLessThanOrEqual(65536)
        ->and($call['parameters_truncated_bytes'])->toBeGreaterThan(65536);
});

test('parameters that cannot be encoded do not take the whole turn down', function () {
    // json_encode returning false is the ONE case that must never be stored
    // verbatim; it was on the "small enough, keep them" branch, so the blocks
    // cast failed and the entire assistant message was lost.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchToolCall('write_file', ['content' => 'bad '.chr(0xC3).chr(0x28).' utf8'], 'mcp-1');
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, ['block_index' => 0, 'block_type' => 'text']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => 'the prose survived']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect(collect($blocks)->firstWhere('type', 'text')['text'])->toBe('the prose survived')
        ->and(collect($blocks)->firstWhere('type', 'tool_call')['parameters'])->toBe([]);
});

test('an empty frame does not erase the block\'s record of being cut off', function () {
    // Replacing "cut off, 90000 bytes in total" with "called with no arguments"
    // is a false statement about the data.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call',
            'tool_name' => 'mcp__bridge__write_file', 'tool_call_id' => 'toolu_1',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, [
            'block_index' => 0, 'content' => '{"body":"'.str_repeat('y', 80000),
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        // A frame that genuinely carries nothing.
        $h->dispatchToolCall('write_file', [], 'mcp-1');
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $call = collect($blocks)->firstWhere('type', 'tool_call');

    expect($call)->toHaveKey('parameters_raw')
        ->and($call['parameters_truncated_bytes'])->toBeGreaterThan(65536);
});

test('a nameless tool block is still dropped', function () {
    // Nothing worth showing, and it would render as an empty wrench.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, ['block_index' => 0, 'block_type' => 'tool_call']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{}']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, ['block_index' => 1, 'block_type' => 'text']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 1, 'content' => 'the answer']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 1]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect(collect($blocks)->where('type', 'tool_call'))->toHaveCount(0)
        ->and(collect($blocks)->firstWhere('type', 'text')['text'])->toBe('the answer');
});

test('a run of local tools is recorded so it can be counted, not lumped', function () {
    // The whole point of the change: "3 commands" instead of "3 tool calls".
    $blocks = recordTurn(function (StreamHandler $h) {
        foreach ([['Bash', '{"command":"a"}'], ['Bash', '{"command":"b"}'], ['Read', '{"file_path":"/x"}']] as $i => [$name, $args]) {
            $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
                'block_index' => $i, 'block_type' => 'tool_call', 'tool_name' => $name,
            ]));
            $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => $i, 'content' => $args]));
            $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => $i]));
        }
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect(collect($blocks)->where('type', 'tool_call')->pluck('tool_name')->all())
        ->toBe(['Bash', 'Bash', 'Read']);
});

test('a helper\'s own prose and thinking are not stored as the main assistant\'s', function () {
    // The stored content is what a fresh session sends back as history, so a
    // helper's words kept there are put in the main assistant's mouth. Its
    // tool calls stay, under their parent, so a reload can still nest them.
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    $handler = recordingHandler();
    $handler->setConversationId((string) $conversation->id);
    ConversationRecorder::attach($handler, $conversation);

    $block = function (int $i, array $start, string $content) use ($handler) {
        $handler->dispatchEvent(wire(MessageTypes::BLOCK_START, ['block_index' => $i] + $start));
        $handler->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => $i, 'content' => $content]));
        $handler->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => $i]));
    };

    $block(0, ['block_type' => 'text'], 'Handing it to a helper. ');
    $block(1, ['block_type' => 'tool_call', 'tool_name' => 'Agent', 'tool_call_id' => 'toolu_agent'], '{"prompt":"go"}');
    $block(2, ['block_type' => 'thinking', 'parent_tool_use_id' => 'toolu_agent'], 'helper thinks');
    $block(3, ['block_type' => 'tool_call', 'tool_name' => 'Bash', 'tool_call_id' => 'toolu_child', 'parent_tool_use_id' => 'toolu_agent'], '{"command":"ls"}');
    $block(4, ['block_type' => 'text', 'parent_tool_use_id' => 'toolu_agent'], 'helper prose');
    $block(5, ['block_type' => 'text'], 'The helper is done.');
    $handler->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));

    $message = $conversation->messages()->where('role', 'assistant')->latest('id')->first();
    $blocks = collect($message->blocks);

    expect($message->content)->toBe('Handing it to a helper. The helper is done.')
        ->and($blocks->pluck('type')->all())->toBe(['text', 'tool_call', 'tool_call', 'text'])
        ->and($blocks->pluck('text')->filter()->implode('|'))->not->toContain('helper thinks')->not->toContain('helper prose')
        ->and($blocks->firstWhere('tool_name', 'Bash')['parent_tool_use_id'])->toBe('toolu_agent')
        ->and($blocks->firstWhere('tool_name', 'Agent'))->not->toHaveKey('parent_tool_use_id');
});

test('a helper\'s prose block left open does not swallow the main reply after it', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, ['block_index' => 0, 'block_type' => 'text', 'parent_tool_use_id' => 'toolu_agent']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => 'helper prose']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, ['block_index' => 1, 'block_type' => 'text']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 1, 'content' => 'main reply']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 1]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0])->toMatchArray(['type' => 'text', 'text' => 'main reply']);
});
