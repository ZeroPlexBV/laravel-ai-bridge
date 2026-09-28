<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Streaming\StreamHandler;

/*
|--------------------------------------------------------------------------
| A real turn, off a real bridge, through this package
|--------------------------------------------------------------------------
|
| Every other test in this suite feeds the recorder frames that a human wrote.
| This one replays the exact bytes a real `@tetrixdev/ai-bridge` put on the wire
| for a real Claude Code turn — captured by the bridge's own end-to-end harness
| against the installed CLI — and asserts this package makes sense of them.
|
| It is the only test that exercises the seam both packages meet at. Everything
| else tests one half against an idea of the other.
|
*/

uses(RefreshDatabase::class);

test('a captured turn from the real bridge records completely', function () {
    $frames = json_decode(
        (string) file_get_contents(__DIR__.'/fixtures/real-turn-0.8.1.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    expect($frames)->not->toBeEmpty();

    $rateLimits = [];
    $blocks = recordTurn(function (StreamHandler $h) use ($frames, &$rateLimits) {
        $h->onRateLimit(function (string $provider, array $info) use (&$rateLimits) {
            $rateLimits[] = [$provider, $info];
        });

        foreach ($frames as $frame) {
            $h->dispatchEvent(wire($frame['event'], $frame['data'] ?? []));
        }
    });

    $tools = collect($blocks)->where('type', 'tool_call')->values();

    // Two shell commands ran on the operator's machine. Before this release the
    // record showed neither of them.
    expect($tools)->toHaveCount(2)
        ->and($tools[0]['tool_name'])->toBe('Bash')
        ->and($tools[1]['tool_name'])->toBe('Bash');

    // Each has the id its result is keyed by, and the result itself.
    foreach ($tools as $tool) {
        expect($tool['tool_call_id'])->toStartWith('toolu_')
            ->and($tool)->toHaveKey('result');
    }

    $outputs = $tools->pluck('result')->implode(' ');
    expect($outputs)->toContain('alpha')->and($outputs)->toContain('beta');

    // Nothing orphaned: every result found the call that produced it.
    expect(collect($blocks)->where('type', 'tool_result'))->toHaveCount(0);

    // The assistant's prose survived alongside the tool calls.
    expect(collect($blocks)->where('type', 'text')->pluck('text')->implode(''))->not->toBe('');

    // A rate_limit notice really does arrive on an ordinary turn — it is not a
    // hypothetical — and it must not be drawn into the answer or end the turn.
    expect($rateLimits)->not->toBeEmpty()
        ->and($rateLimits[0][0])->toBe('claude');
});

test('a captured turn reports what it cost, cache included', function () {
    $frames = json_decode(
        (string) file_get_contents(__DIR__.'/fixtures/real-turn-0.8.1.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $handler = recordingHandler();
    foreach ($frames as $frame) {
        $handler->dispatchEvent(wire($frame['event'], $frame['data'] ?? []));
    }

    $usage = $handler->lastDoneUsage();
    $meta = $handler->lastDoneMeta();

    // The cache counters dominate this turn by two orders of magnitude — which
    // is exactly why forwarding only input/output understates it.
    expect($usage['cache_read_input_tokens'])->toBeGreaterThan($usage['input_tokens'])
        ->and($usage['cache_creation_input_tokens'])->toBeGreaterThan(0)
        ->and($meta['model'])->toBeString()
        // The real bridge DOES attach `cli_session_id` to `done` — this fixture
        // proves it rather than supposing it — and neither place this package
        // hands the metadata out passes it on.
        ->and($meta)->not->toHaveKey('cli_session_id');

    $raw = collect($frames)->firstWhere('event', MessageTypes::DONE)['data'];
    expect($raw)->toHaveKey('cli_session_id')
        ->and(\Tetrix\AiBridge\Streaming\BufferingSink::publicDoneMeta($raw))
        ->not->toHaveKey('cli_session_id');
});

/*
|--------------------------------------------------------------------------
| A turn with a background helper
|--------------------------------------------------------------------------
|
| `fixtures/real-turn-background-helper.json` is what `@tetrixdev/ai-bridge`
| (tetrixdev/ai-bridge#39) emits for its captured Claude Code 2.1.280 turn
| `tests/providers/fixtures/claude-background-subagent-turn.ndjson`: that
| NDJSON replayed through the bridge's own ClaudeAdapter (its test harness),
| with `done` re-sent last carrying `cli_session_id`, as Bridge does.
|
| The main assistant starts a helper in the background, says so, and ends
| its message; the helper keeps working for ~45 s — a shell command of its
| own, its prose, its closing summary — and only then does the turn end.
|
*/

function backgroundHelperFrames(): array
{
    return json_decode(
        (string) file_get_contents(__DIR__.'/fixtures/real-turn-background-helper.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
}

test('a captured background-helper turn records whose call was whose', function () {
    $frames = backgroundHelperFrames();

    $tasks = [];
    $blocks = recordTurn(function (StreamHandler $h) use ($frames, &$tasks) {
        $h->onTask(function (array $task) use (&$tasks) {
            $tasks[] = $task;
        });

        foreach ($frames as $frame) {
            $h->dispatchEvent(wire($frame['event'], $frame['data'] ?? []));
        }
    });

    $tools = collect($blocks)->where('type', 'tool_call')->keyBy('tool_name');

    // The spawning call is the main assistant's: no parent key at all.
    $agent = $tools['Agent'];
    expect($agent)->not->toHaveKey('parent_tool_use_id')
        ->and($agent['result'])->toContain('Async agent launched');

    // The helper's own call is stored under the helper that made it, and its
    // result found it — the key is the spawning call's id.
    $bash = $tools['Bash'];
    expect($bash['parent_tool_use_id'])->toBe($agent['tool_call_id'])
        ->and($bash['result'])->toBe('helper-done');

    expect(collect($blocks)->where('type', 'tool_result'))->toHaveCount(0);

    // The helper's closing prose is not stored as a text block of the main
    // assistant's: only the main assistant's own two replies are.
    expect(collect($blocks)->where('type', 'text')->pluck('text')->all())
        ->toBe(['Helper started in the background.', 'The background helper finished and printed "helper-done".']);

    // Every task event arrived, untouched and in order: the helper, the shell
    // command it ran as a task of its own, and the helper's end.
    $taskFrames = collect($frames)->where('event', MessageTypes::TASK)->pluck('data')->values()->all();
    expect($tasks)->toBe($taskFrames)
        ->and(array_column($tasks, 'phase'))->toBe(['started', 'progress', 'started', 'finished', 'updated', 'finished']);

    $helper = collect($tasks)->where('tool_use_id', $agent['tool_call_id']);
    expect($helper->first())->toMatchArray(['phase' => 'started', 'task_type' => 'local_agent', 'is_backgrounded' => true])
        ->and($helper->last())->toMatchArray(['phase' => 'finished', 'status' => 'completed'])
        ->and($helper->last()['usage']['total_tokens'])->toBe(24742);
});

test('a captured background helper finishes after its spawning call returned and after the main reply', function () {
    $frames = collect(backgroundHelperFrames())->values();
    $agentId = $frames->first(fn ($f) => $f['event'] === MessageTypes::BLOCK_START
        && ($f['data']['tool_name'] ?? null) === 'Agent')['data']['tool_call_id'];

    $at = fn (callable $match) => $frames->search(fn ($f) => $match($f['event'], $f['data'] ?? []));

    $spawningCallReturned = $at(fn ($e, $d) => $e === MessageTypes::TOOL_RESULT && $d['tool_call_id'] === $agentId);
    $mainReply = $at(fn ($e, $d) => $e === MessageTypes::BLOCK_START && $d['block_type'] === 'text' && ! isset($d['parent_tool_use_id']));
    $helperFinished = $at(fn ($e, $d) => $e === MessageTypes::TASK && $d['phase'] === 'finished' && $d['tool_use_id'] === $agentId);

    // What a consumer must not assume: the spawning call's result, and the main
    // assistant's reply, both come long before the helper is done.
    expect($spawningCallReturned)->toBeInt()
        ->and($helperFinished)->toBeInt()
        ->and($spawningCallReturned)->toBeLessThan($helperFinished)
        ->and($mainReply)->toBeLessThan($helperFinished);
});

test('a captured background-helper turn buffers and replays byte for byte', function () {
    $frames = backgroundHelperFrames();
    config()->set('ai-bridge.streaming.suppress_thinking_blocks', true);

    $store = new \Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore();
    $handler = recordingHandler();
    \Tetrix\AiBridge\Streaming\BufferingSink::attach($handler, $store);

    foreach ($frames as $frame) {
        $handler->dispatchEvent(wire($frame['event'], $frame['data'] ?? []));
    }

    $buffered = $store->range($handler->requestId);

    // Every task, and every helper tool_result, is in the buffer exactly as
    // the bridge sent it.
    foreach ([MessageTypes::TASK, MessageTypes::TOOL_RESULT] as $event) {
        expect(json_encode(collect($buffered)->where('event', $event)->pluck('data')->values()))
            ->toBe(json_encode(collect($frames)->where('event', $event)->pluck('data')->values()));
    }

    // The turn's helper totals reach the browser; the session handle does not.
    $done = collect($buffered)->firstWhere('event', MessageTypes::DONE)['data'];
    expect($done['subagent_stats'])->toBe(collect($frames)->firstWhere('event', MessageTypes::DONE)['data']['subagent_stats'])
        ->and($done)->not->toHaveKey('cli_session_id');

    // Replaying from after the main assistant's reply still yields the
    // helper's later events — what a browser reconnecting then would need.
    $mainText = collect($buffered)->first(fn ($e) => $e['event'] === 'block_start'
        && $e['data']['block_type'] === 'text' && ! isset($e['data']['parent_tool_use_id']));
    $later = collect($store->range($handler->requestId, $mainText['index']));
    expect($later->where('event', MessageTypes::TASK)->pluck('data.phase')->values()->all())
        ->toBe(['progress', 'started', 'finished', 'updated', 'finished']);
});
