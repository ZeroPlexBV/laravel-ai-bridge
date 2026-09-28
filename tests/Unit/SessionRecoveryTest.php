<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Models\Message;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/*
|--------------------------------------------------------------------------
| Server-Owned Session Recovery Tests
|--------------------------------------------------------------------------
|
| Covers the server-owned CLI session lifecycle in MessageHandler:
|  - a `done` event persisting cli_session_id onto its conversation;
|  - the recoverable `session_lost` path wiping the dead session and silently
|    re-issuing the turn as a fresh session;
|  - the retry-once guard that surfaces a recurring session_lost instead of
|    looping.
|
*/

uses(RefreshDatabase::class);

/** Minimal no-op provider for driving a StreamHandler in tests. */
function recoveryProvider(): StreamableProvider
{
    return new class implements StreamableProvider
    {
        public function setConversationId(string $c): static
        {
            return $this;
        }

        public function setMessage(string $m): static
        {
            return $this;
        }

        public function setOptions(array $o): static
        {
            return $this;
        }

        public function start(): void {}

        public function cancel(): void {}

        public function getStreamHandler(): StreamHandler
        {
            throw new RuntimeException('n/a');
        }

        public function markCompleted(): void {}
    };
}

/** A bridge-mode StreamHandler bound to the given (numeric) conversation id. */
function recoveryHandler(string $conversationId): StreamHandler
{
    $handler = new StreamHandler(recoveryProvider());
    $handler->setMode(ProviderMode::Bridge);
    $handler->setConversationId($conversationId);

    return $handler;
}

function recoveryMessageHandler(BridgeConnectionManager $manager): MessageHandler
{
    return new MessageHandler(
        connectionManager: $manager,
        tokenManager: app(TokenManager::class),
        toolRegistry: new ToolRegistry(),
    );
}

/** Encode a stream-enveloped message for handleMessage(). */
function streamMessage(string $event, array $data): string
{
    return json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => $event,
        'data' => $data,
    ]);
}

beforeEach(function () {
    Event::fake();
});

// --- persisting cli_session_id from `done` ---

test('done event persists the cli_session_id onto its conversation', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    $manager = new BridgeConnectionManager();
    $handler = recoveryHandler((string) $conversation->id);

    $manager->addConnection('user-1', 'conn-1');
    $manager->registerPendingRequest('req-1', $handler, 'user-1');

    recoveryMessageHandler($manager)->handleMessage('conn-1', null, streamMessage(
        MessageTypes::DONE,
        ['cli_session_id' => 'sess-xyz'],
    ));

    expect($conversation->fresh()->cli_session_id)->toBe('sess-xyz');
    expect($manager->getPendingRequest('req-1'))->toBeNull();
});

test('done event without a cli_session_id leaves the stored session intact', function () {
    $conversation = Conversation::create([
        'mode' => 'bridge', 'provider' => 'claude', 'cli_session_id' => 'existing-sess',
    ]);
    $manager = new BridgeConnectionManager();
    $handler = recoveryHandler((string) $conversation->id);

    $manager->addConnection('user-1', 'conn-1');
    $manager->registerPendingRequest('req-1', $handler, 'user-1');

    recoveryMessageHandler($manager)->handleMessage('conn-1', null, streamMessage(
        MessageTypes::DONE,
        [],
    ));

    expect($conversation->fresh()->cli_session_id)->toBe('existing-sess');
});

// --- session_lost recovery ---

test('session_lost wipes the dead session and re-issues the turn fresh', function () {
    $conversation = Conversation::create([
        'mode' => 'bridge', 'provider' => 'claude', 'cli_session_id' => 'dead-sess',
    ]);
    $conversation->appendMessage(Message::ROLE_USER, 'I enter the cave');

    $sent = [];
    $manager = new BridgeConnectionManager();
    $manager->setSendCallback(function ($connection, $payload) use (&$sent) {
        $sent[] = $payload;

        return true;
    });

    $handler = recoveryHandler((string) $conversation->id);
    $errorCount = 0;
    $handler->onError(function () use (&$errorCount) {
        $errorCount++;
    });

    $manager->addConnection('user-1', 'conn-1');
    $manager->registerPendingRequest('req-1', $handler, 'user-1');

    recoveryMessageHandler($manager)->handleMessage('conn-1', null, streamMessage(
        MessageTypes::ERROR,
        ['code' => 'session_lost', 'message' => 'cannot resume'],
    ));

    // The dead session id is wiped from the conversation.
    expect($conversation->fresh()->cli_session_id)->toBeNull();
    // The browser never sees an error — recovery is transparent.
    expect($errorCount)->toBe(0);
    // The request stays pending so the re-issued turn continues on it.
    expect($manager->getPendingRequest('req-1'))->not->toBeNull();
    // A fresh ai_request was re-sent to the bridge, same request_id, no session.
    expect($sent)->toHaveCount(1);
    expect($sent[0]['type'])->toBe(MessageTypes::AI_REQUEST);
    expect($sent[0]['request_id'])->toBe('req-1');
    expect($sent[0]['cli_session_id'])->toBeNull();
    expect($sent[0]['message'])->toBe('I enter the cave');
    // The current turn is popped off, so there is no prior history — and an
    // empty optional field is omitted rather than sent empty, which is the
    // convention AiRequestPayload applies to every builder.
    expect($sent[0])->not->toHaveKey('history');
});

test('session_lost recovery carries prior turns as history', function () {
    $conversation = Conversation::create([
        'mode' => 'bridge', 'provider' => 'claude', 'cli_session_id' => 'dead-sess',
    ]);
    $conversation->appendMessage(Message::ROLE_USER, 'I enter the cave');
    $conversation->appendMessage(Message::ROLE_ASSISTANT, 'The cave mouth yawns before you.');
    $conversation->appendMessage(Message::ROLE_USER, 'I light my torch');

    $sent = [];
    $manager = new BridgeConnectionManager();
    $manager->setSendCallback(function ($connection, $payload) use (&$sent) {
        $sent[] = $payload;

        return true;
    });

    $handler = recoveryHandler((string) $conversation->id);
    $manager->addConnection('user-1', 'conn-1');
    $manager->registerPendingRequest('req-1', $handler, 'user-1');

    recoveryMessageHandler($manager)->handleMessage('conn-1', null, streamMessage(
        MessageTypes::ERROR,
        ['code' => 'session_lost', 'message' => 'cannot resume'],
    ));

    expect($sent[0]['message'])->toBe('I light my torch');
    expect($sent[0]['history'])->toBe([
        ['role' => 'user', 'content' => 'I enter the cave'],
        ['role' => 'assistant', 'content' => 'The cave mouth yawns before you.'],
    ]);
});

test('a second session_lost on the same request surfaces the error instead of looping', function () {
    $conversation = Conversation::create([
        'mode' => 'bridge', 'provider' => 'claude', 'cli_session_id' => 'dead-sess',
    ]);
    $conversation->appendMessage(Message::ROLE_USER, 'hello');

    $manager = new BridgeConnectionManager();
    $manager->setSendCallback(fn ($connection, $payload) => true);

    $handler = recoveryHandler((string) $conversation->id);
    $errorCount = 0;
    $handler->onError(function () use (&$errorCount) {
        $errorCount++;
    });

    $manager->addConnection('user-1', 'conn-1');
    $manager->registerPendingRequest('req-1', $handler, 'user-1');

    $mh = recoveryMessageHandler($manager);
    $lost = streamMessage(MessageTypes::ERROR, ['code' => 'session_lost', 'message' => 'cannot resume']);

    // First session_lost — recovered silently.
    $mh->handleMessage('conn-1', null, $lost);
    expect($errorCount)->toBe(0);
    expect($manager->getPendingRequest('req-1'))->not->toBeNull();

    // Second session_lost on the same request — surfaced as a real error.
    $mh->handleMessage('conn-1', null, $lost);
    expect($errorCount)->toBe(1);
    expect($manager->getPendingRequest('req-1'))->toBeNull();
});

test('session_lost recovery fails cleanly when the bridge re-send fails', function () {
    $conversation = Conversation::create([
        'mode' => 'bridge', 'provider' => 'claude', 'cli_session_id' => 'dead-sess',
    ]);
    $conversation->appendMessage(Message::ROLE_USER, 'hello');

    $manager = new BridgeConnectionManager();
    $manager->setSendCallback(fn ($connection, $payload) => false); // re-send fails

    $handler = recoveryHandler((string) $conversation->id);
    $errorCount = 0;
    $handler->onError(function () use (&$errorCount) {
        $errorCount++;
    });

    $manager->addConnection('user-1', 'conn-1');
    $manager->registerPendingRequest('req-1', $handler, 'user-1');

    recoveryMessageHandler($manager)->handleMessage('conn-1', null, streamMessage(
        MessageTypes::ERROR,
        ['code' => 'session_lost', 'message' => 'cannot resume'],
    ));

    // A failed re-send must end the turn, not leave it hanging.
    expect($errorCount)->toBe(1);
    expect($manager->getPendingRequest('req-1'))->toBeNull();
});

test('session_lost recovery carries the conversation working directory', function () {
    // The recovery builder used to assemble its own array and never learned
    // about working_dir. A recovered turn then ran in the bridge's empty
    // scratch directory and answered confidently about a repository the CLI
    // could not see — and the fresh session it created was bound to that
    // scratch directory, so every later turn was refused working_dir_changed
    // and the conversation was dead until the bridge restarted.
    $conversation = Conversation::create([
        'mode' => 'bridge',
        'provider' => 'claude',
        'cli_session_id' => 'dead-sess',
        'working_dir' => '/repos/studio',
    ]);
    $conversation->appendMessage(Message::ROLE_USER, 'run the tests');

    $sent = [];
    $manager = new BridgeConnectionManager();
    $manager->setSendCallback(function ($connection, $payload) use (&$sent) {
        $sent[] = $payload;

        return true;
    });

    $manager->addConnection('user-1', 'conn-1');
    $manager->registerPendingRequest('req-1', recoveryHandler((string) $conversation->id), 'user-1');

    recoveryMessageHandler($manager)->handleMessage('conn-1', null, streamMessage(
        MessageTypes::ERROR,
        ['code' => 'session_lost', 'message' => 'cannot resume'],
    ));

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['working_dir'])->toBe('/repos/studio')
        ->and($sent[0]['cli_session_id'])->toBeNull();
});

test('session_lost re-issue keeps the relayed turn\'s bridge_prompt and open input', function () {
    $conversation = Conversation::create([
        'mode' => 'bridge', 'provider' => 'claude', 'cli_session_id' => 'dead-sess',
    ]);
    $conversation->appendMessage(Message::ROLE_USER, 'I enter the cave');

    $sent = [];
    $manager = new BridgeConnectionManager();
    $manager->setSendCallback(function ($connection, $payload) use (&$sent) {
        $sent[] = $payload;

        return true;
    });
    $manager->addConnection('user-1', 'conn-1');

    $messages = recoveryMessageHandler($manager);
    $messages->registerRelayedRequest('req-1', 'user-1', (string) $conversation->id, [
        'bridge_prompt' => ['mode' => 'append', 'text' => 'Be brief.'],
        'accepts_input' => true,
    ]);

    $messages->handleMessage('conn-1', null, streamMessage(
        MessageTypes::ERROR,
        ['code' => 'session_lost', 'message' => 'cannot resume'],
    ));

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['bridge_prompt'])->toBe(['mode' => 'append', 'text' => 'Be brief.'])
        ->and($sent[0]['options']['accepts_input'])->toBeTrue();
});
