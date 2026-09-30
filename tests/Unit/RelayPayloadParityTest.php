<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Protocol\AiRequestPayload;
use Tetrix\AiBridge\Streaming\BridgeStream;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/*
|--------------------------------------------------------------------------
| The relay round trip
|--------------------------------------------------------------------------
|
| The direct WebSocket path and the PHP-FPM relay path now share a builder, so
| comparing the builder against itself proves very little. The drift that can
| still happen is in the hand-written hops either side of it:
| BridgeStream::relayViaHttpApi() copies the payload into an HTTP body, and
| BridgeWebSocketServer::apiRequest() maps that body back through the builder.
| A field added to the builder and missed in those two works under Octane and
| silently does nothing under PHP-FPM — the deployment most apps run.
|
| So this drives the real relay, captures the real body, and replays the
| server's real mapping.
|
*/

/**
 * Replay the relay body exactly as the server does.
 *
 * Calls the SAME method BridgeWebSocketServer::apiRequest() calls, rather than
 * re-implementing its mapping here — a test that re-implements the thing under
 * test proves only that the test agrees with itself. Deleting a field from
 * AiRequestPayload::fromRelayBody() now fails this file.
 */
function replayRelayBody(array $body): array
{
    return AiRequestPayload::fromRelayBody($body, $body['request_id'] ?? '');
}

function relayedBodyFor(array $options): array
{
    Http::fake(['*/api/request' => Http::response(['ok' => true], 200)]);

    $stream = new BridgeStream(
        // Empty on purpose: with no in-memory connection, start() takes the
        // PHP-FPM relay branch, which is the one under test.
        new BridgeConnectionManager(),
        new ToolRegistry(),
        app(TokenManager::class),
        'user-1',
    );
    $stream->setConversationId('conv-1');
    $stream->setProvider('claude');
    $stream->setMessage('hello');
    $stream->setOptions($options);

    $direct = $stream->buildRequestBody();
    $stream->start();

    $sent = [];
    Http::assertSent(function ($request) use (&$sent) {
        $sent = $request->data();

        return true;
    });

    return ['direct' => $direct, 'relayed' => $sent];
}

test('a fully-populated turn survives the relay unchanged', function () {
    ['direct' => $direct, 'relayed' => $relayBody] = relayedBodyFor([
        'system_prompt' => 'be useful',
        'model' => 'sonnet',
        'temperature' => 0.5,
        'max_tokens' => 1000,
        'cli_session_id' => null,
        'messages' => [['role' => 'user', 'content' => 'earlier']],
        'working_dir' => '/repos/studio',
        'attachments' => [[
            'id' => 'att_1', 'name' => 'a.pdf', 'mime_type' => 'application/pdf',
            'size' => 10, 'sha256' => 'abc', 'url' => 'https://x.test/a',
        ]],
    ]);

    expect(replayRelayBody($relayBody))->toBe($direct);
});

test('working_dir reaches the relay body', function () {
    // Named explicitly: this is the field whose loss would look like the
    // workspace feature simply not working under PHP-FPM.
    ['relayed' => $relayBody] = relayedBodyFor([
        'cli_session_id' => null,
        'working_dir' => '/repos/studio',
    ]);

    expect($relayBody['working_dir'])->toBe('/repos/studio');
});

test('attachments reach the relay body', function () {
    ['relayed' => $relayBody] = relayedBodyFor([
        'cli_session_id' => null,
        'attachments' => [[
            'id' => 'att_1', 'name' => 'a.pdf', 'mime_type' => 'application/pdf',
            'size' => 10, 'sha256' => 'abc', 'url' => 'https://x.test/a',
        ]],
    ]);

    expect($relayBody['attachments'][0]['id'])->toBe('att_1');
});

test('a resumed turn survives the relay unchanged', function () {
    ['direct' => $direct, 'relayed' => $relayBody] = relayedBodyFor([
        'cli_session_id' => 'sess-1',
        'messages' => [['role' => 'user', 'content' => 'earlier']],
        'working_dir' => '/repos/studio',
    ]);

    expect(replayRelayBody($relayBody))->toBe($direct)
        // History is dropped on both sides, not just one.
        ->and($direct)->not->toHaveKey('history');
});

test('a minimal turn survives the relay unchanged', function () {
    ['direct' => $direct, 'relayed' => $relayBody] = relayedBodyFor(['cli_session_id' => null]);

    expect(replayRelayBody($relayBody))->toBe($direct);
});

test('a malformed options field is rejected rather than thrown past the loop', function () {
    // apiRequest() runs inside the ReactPHP data callback, which has no
    // try/catch: a TypeError there does not fail one request, it exits the
    // serve process and drops every connected bridge.
    expect(AiRequestPayload::build([
        'request_id' => 'r', 'message' => 'm', 'options' => 'not-an-array',
    ]))->not->toHaveKey('options');

    expect(AiRequestPayload::build([
        'request_id' => 'r', 'message' => 'm', 'tools' => 'nope', 'history' => 'nope',
    ]))->not->toHaveKey('tools');

    expect(fn () => AiRequestPayload::build([
        'request_id' => 'r', 'message' => 'm', 'attachments' => 'nope',
    ]))->toThrow(InvalidArgumentException::class);
});

test('a non-string request_id is refused rather than thrown past the event loop', function () {
    // apiRequest() shape-checks these itself before anything touches them.
    // strict_types=1 makes a numeric request_id passed to a string parameter a
    // TypeError, raised inside a ReactPHP data callback that has no try/catch —
    // which exits the process and drops every connected bridge rather than
    // failing this one request.
    foreach ([['a'], true, ['x' => 1]] as $bad) {
        expect(fn () => AiRequestPayload::fromRelayBody(
            ['message' => 'hi', 'conversation_id' => $bad],
            'req-1',
        ))->toThrow(InvalidArgumentException::class);
    }
});

test('numeric relay fields are coerced rather than refused', function () {
    // An app that sends an integer conversation_id is being sloppy, not
    // hostile; coercing is friendlier than a 400 and cannot throw.
    $payload = AiRequestPayload::fromRelayBody(
        ['message' => 'hi', 'conversation_id' => 42, 'provider' => 'claude'],
        'req-1',
    );

    expect($payload['conversation_id'])->toBe('42');
});

test('accepts_input reaches the bridge the same on both paths', function () {
    // The option that keeps a turn's input open. Lost on the relay, it would
    // work under Octane and every mid-turn message would be held under PHP-FPM.
    ['direct' => $direct, 'relayed' => $relayBody] = relayedBodyFor([
        'cli_session_id' => null,
        'accepts_input' => true,
    ]);

    expect($direct['options'])->toBe(['accepts_input' => true])
        ->and($relayBody['options'])->toBe(['accepts_input' => true])
        ->and(replayRelayBody($relayBody))->toBe($direct);
});

test('a turn that did not ask for input sends no accepts_input at all', function (array $options) {
    ['direct' => $direct, 'relayed' => $relayBody] = relayedBodyFor(['cli_session_id' => null] + $options);

    expect($direct)->not->toHaveKey('options')
        ->and(replayRelayBody($relayBody))->toBe($direct);
})->with([
    'absent' => [[]],
    'false' => [['accepts_input' => false]],
    'a truthy string' => [['accepts_input' => 'yes']],
]);

test('only a real true in a relay body turns input on', function () {
    // The relay body is caller-supplied JSON; "1" or "yes" must not be what
    // switches a turn into the mode with background tasks on.
    foreach (['1', 1, 'true', 'yes', false] as $value) {
        expect(AiRequestPayload::fromRelayBody(['message' => 'hi', 'options' => ['accepts_input' => $value]], 'req-1'))
            ->not->toHaveKey('options');
    }

    expect(AiRequestPayload::fromRelayBody(['message' => 'hi', 'options' => ['accepts_input' => true, 'model' => 'sonnet']], 'req-1')['options'])
        ->toBe(['accepts_input' => true, 'model' => 'sonnet']);
});
