<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\Contracts\SendableConnection;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Events\BridgeConnected;
use Tetrix\AiBridge\Events\BridgeDisconnected;
use Tetrix\AiBridge\Events\TurnLostAfterDrop;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tests\TestCase;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/*
|--------------------------------------------------------------------------
| BridgeConnectionManager Unit Tests
|--------------------------------------------------------------------------
|
| These tests verify connection lifecycle, pending request tracking,
| and error dispatch on disconnection.
|
*/


function createTestStreamHandler(): StreamHandler
{
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $handler = new StreamHandler($provider);
    $handler->setMode(ProviderMode::Byok);
    $handler->setConversationId('test-conv');

    return $handler;
}

beforeEach(function () {
    Event::fake();
    $this->manager = new BridgeConnectionManager();
});

test('addConnection() registers a connection for a user', function () {
    $this->manager->addConnection('user-1', 'conn-abc');

    expect($this->manager->hasConnection('user-1'))->toBeTrue();
    expect($this->manager->connectionCount())->toBe(1);

    Event::assertDispatched(BridgeConnected::class, function (BridgeConnected $event) {
        return $event->userId === 'user-1' && $event->connectionId === 'conn-abc';
    });
});

test('removeConnection() removes a registered connection', function () {
    $this->manager->addConnection('user-1', 'conn-abc');
    $this->manager->removeConnection('user-1');

    expect($this->manager->hasConnection('user-1'))->toBeFalse();
    expect($this->manager->connectionCount())->toBe(0);

    Event::assertDispatched(BridgeDisconnected::class, function (BridgeDisconnected $event) {
        return $event->userId === 'user-1';
    });
});

test('removeConnection() is safe for non-existent user', function () {
    // Should not throw
    $this->manager->removeConnection('nonexistent');

    expect($this->manager->connectionCount())->toBe(0);
});

test('hasConnection() returns false for unregistered user', function () {
    expect($this->manager->hasConnection('user-999'))->toBeFalse();
});

test('getConnection() returns connection metadata', function () {
    $this->manager->addConnection('user-1', 'conn-abc', 'ws-object', [['model' => 'gpt-4']]);

    $conn = $this->manager->getConnection('user-1');

    expect($conn)->not->toBeNull();
    expect($conn['connection_id'])->toBe('conn-abc');
    expect($conn['connected_at'])->toBeInt();
    expect($conn['connection'])->toBe('ws-object');
    expect($conn['providers'])->toBe([['model' => 'gpt-4']]);
});

test('getConnection() returns null for unregistered user', function () {
    expect($this->manager->getConnection('user-999'))->toBeNull();
});

test('getConnectionId() returns connection ID for registered user', function () {
    $this->manager->addConnection('user-1', 'conn-xyz');

    expect($this->manager->getConnectionId('user-1'))->toBe('conn-xyz');
});

test('getConnectionId() returns null for unregistered user', function () {
    expect($this->manager->getConnectionId('user-999'))->toBeNull();
});

test('registerPendingRequest() and getPendingRequest() lifecycle', function () {
    $handler = createTestStreamHandler();

    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $retrieved = $this->manager->getPendingRequest('req-1');

    expect($retrieved)->toBe($handler);
});

test('getPendingRequest() returns null for non-existent request', function () {
    expect($this->manager->getPendingRequest('nonexistent'))->toBeNull();
});

test('removePendingRequest() removes a tracked request', function () {
    $handler = createTestStreamHandler();

    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');
    $this->manager->removePendingRequest('req-1');

    expect($this->manager->getPendingRequest('req-1'))->toBeNull();
});

test('failPendingRequestsForUser() dispatches errors to all pending handlers', function () {
    $handler1 = createTestStreamHandler();
    $handler2 = createTestStreamHandler();

    $error1Code = null;
    $error2Code = null;

    $handler1->onError(function (string $code) use (&$error1Code) {
        $error1Code = $code;
    });

    $handler2->onError(function (string $code) use (&$error2Code) {
        $error2Code = $code;
    });

    $this->manager->registerPendingRequest('req-1', $handler1, 'user-1');
    $this->manager->registerPendingRequest('req-2', $handler2, 'user-1');
    $this->manager->addConnection('user-1', 'conn-1');

    // Remove connection triggers failPendingRequestsForUser
    $this->manager->removeConnection('user-1');

    expect($error1Code)->toBe('bridge_disconnected');
    expect($error2Code)->toBe('bridge_disconnected');

    // Pending requests should be cleaned up
    expect($this->manager->getPendingRequest('req-1'))->toBeNull();
    expect($this->manager->getPendingRequest('req-2'))->toBeNull();
});

test('getPendingRequestUserId() returns correct userId (SEC-009)', function () {
    $handler = createTestStreamHandler();

    $this->manager->registerPendingRequest('req-1', $handler, 'user-42');

    expect($this->manager->getPendingRequestUserId('req-1'))->toBe('user-42');
});

test('getPendingRequestUserId() returns null for non-existent request', function () {
    expect($this->manager->getPendingRequestUserId('nonexistent'))->toBeNull();
});

test('getPendingRequestUserId() returns null when userId is empty', function () {
    $handler = createTestStreamHandler();

    $this->manager->registerPendingRequest('req-1', $handler, '');

    expect($this->manager->getPendingRequestUserId('req-1'))->toBeNull();
});

test('reconnection with bridge_reconnecting error code (UX-009)', function () {
    $handler = createTestStreamHandler();
    $receivedCode = null;
    $receivedMessage = null;

    $handler->onError(function (string $code, string $message) use (&$receivedCode, &$receivedMessage) {
        $receivedCode = $code;
        $receivedMessage = $message;
    });

    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');
    $this->manager->addConnection('user-1', 'conn-old');

    // Adding a new connection for the same user replaces the old one
    // This should trigger 'bridge_reconnecting' error code
    $this->manager->addConnection('user-1', 'conn-new');

    expect($receivedCode)->toBe('bridge_reconnecting');
    expect($receivedMessage)->toContain('reconnecting');
});

test('connectedUserIds() returns all connected user IDs', function () {
    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');
    $this->manager->addConnection('user-3', 'conn-3');

    $ids = $this->manager->connectedUserIds();

    expect($ids)->toContain('user-1', 'user-2', 'user-3');
    expect($ids)->toHaveCount(3);
});

test('getUserIdByConnectionId() returns correct user ID', function () {
    $this->manager->addConnection('user-42', 'conn-xyz');

    expect($this->manager->getUserIdByConnectionId('conn-xyz'))->toBe('user-42');
});

test('getUserIdByConnectionId() returns null for unknown connection', function () {
    expect($this->manager->getUserIdByConnectionId('conn-unknown'))->toBeNull();
});

test('removeConnectionByConnectionId() removes the correct connection', function () {
    $this->manager->addConnection('user-1', 'conn-abc');
    $this->manager->addConnection('user-2', 'conn-def');

    $this->manager->removeConnectionByConnectionId('conn-abc');

    expect($this->manager->hasConnection('user-1'))->toBeFalse();
    expect($this->manager->hasConnection('user-2'))->toBeTrue();
});

test('setProviders() updates provider capabilities', function () {
    $this->manager->addConnection('user-1', 'conn-1');

    expect($this->manager->getProviders('user-1'))->toBe([]);

    $this->manager->setProviders('user-1', [['model' => 'claude-3']]);

    expect($this->manager->getProviders('user-1'))->toBe([['model' => 'claude-3']]);
});

test('addConnection() converts integer userId to string', function () {
    $this->manager->addConnection(42, 'conn-1');

    expect($this->manager->hasConnection(42))->toBeTrue();
    expect($this->manager->hasConnection('42'))->toBeTrue();
});

test('sendToUser() returns false when no connection exists', function () {
    expect($this->manager->sendToUser('user-999', ['type' => 'test']))->toBeFalse();
});

test('sendToUser() returns false when no send callback and no BridgeConnection', function () {
    $this->manager->addConnection('user-1', 'conn-1', 'plain-object');

    expect($this->manager->sendToUser('user-1', ['type' => 'test']))->toBeFalse();
});

test('sendToUser() uses send callback when configured', function () {
    $sentPayload = null;
    $this->manager->setSendCallback(function ($connection, array $payload) use (&$sentPayload) {
        $sentPayload = $payload;
        return true;
    });

    $this->manager->addConnection('user-1', 'conn-1', 'ws-conn-obj');

    $result = $this->manager->sendToUser('user-1', ['type' => 'test', 'data' => 'hello']);

    expect($result)->toBeTrue();
    expect($sentPayload)->toBe(['type' => 'test', 'data' => 'hello']);
});

// --- BL-016: Reconnection race — getConnectionId() guard ---

test('getConnectionId() returns new connection ID after reconnect (BL-016)', function () {
    // This tests the guard logic used in BridgeWebSocketHandler::onClose():
    // if manager's current connectionId !== closing connectionId, skip removeConnection.
    $this->manager->addConnection('user-1', 'conn-old');

    // Simulate reconnect: add new connection which replaces old one
    $this->manager->addConnection('user-1', 'conn-new');

    // After reconnect, the manager's current connection ID for user-1 is conn-new
    expect($this->manager->getConnectionId('user-1'))->toBe('conn-new');

    // The guard in onClose checks: if (currentConnectionId !== closingConnectionId)
    // Simulate old connection firing onClose: getConnectionId('user-1') === 'conn-new' !== 'conn-old'
    // => guard fires, removeConnection is skipped
    $closingConnectionId = 'conn-old';
    $currentConnectionId = $this->manager->getConnectionId('user-1');

    // Verify the guard condition is true (skip removeConnection)
    expect($currentConnectionId !== $closingConnectionId)->toBeTrue();
});

test('getConnectionId() matches closing connection when no reconnect occurred (BL-016)', function () {
    // Normal close without reconnect: guard should allow removeConnection to proceed
    $this->manager->addConnection('user-1', 'conn-1');

    $closingConnectionId = 'conn-1';
    $currentConnectionId = $this->manager->getConnectionId('user-1');

    // Guard condition: currentConnectionId === closingConnectionId => allow removeConnection
    expect($currentConnectionId)->toBe($closingConnectionId);
});

test('removeConnection does not evict new connection when called for old one (BL-016)', function () {
    // Full scenario: reconnect race where old onClose must not evict the new connection.
    // BridgeWebSocketHandler.onClose() uses getConnectionId() to guard removeConnection().
    // We simulate that guard logic here directly on the manager.
    $errorFired = false;
    $handler = createTestStreamHandler();
    $handler->onError(function () use (&$errorFired) { $errorFired = true; });

    $this->manager->addConnection('user-1', 'conn-old');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    // Bridge reconnects — conn-new replaces conn-old; pending request fails with bridge_reconnecting
    $this->manager->addConnection('user-1', 'conn-new');

    // Now the new connection is established; a new request is in flight
    $handler2 = createTestStreamHandler();
    $error2Fired = false;
    $handler2->onError(function () use (&$error2Fired) { $error2Fired = true; });
    $this->manager->registerPendingRequest('req-2', $handler2, 'user-1');

    // Simulate onClose firing for the old connection.
    // The guard: if (getConnectionId('user-1') !== 'conn-old') => skip removeConnection
    $closingId = 'conn-old';
    $currentId = $this->manager->getConnectionId('user-1');

    if ($currentId === $closingId) {
        $this->manager->removeConnection('user-1', 'websocket_closed');
    }
    // else: skip — the new connection is active

    // New connection and request must still be intact
    expect($this->manager->hasConnection('user-1'))->toBeTrue();
    expect($this->manager->getConnectionId('user-1'))->toBe('conn-new');
    expect($this->manager->getPendingRequest('req-2'))->not->toBeNull();
    expect($error2Fired)->toBeFalse();
});

test('failPendingRequestsForUser does not affect other users pending requests', function () {
    $handler1 = createTestStreamHandler();
    $handler2 = createTestStreamHandler();

    $error1Fired = false;
    $error2Fired = false;

    $handler1->onError(function () use (&$error1Fired) { $error1Fired = true; });
    $handler2->onError(function () use (&$error2Fired) { $error2Fired = true; });

    $this->manager->registerPendingRequest('req-1', $handler1, 'user-1');
    $this->manager->registerPendingRequest('req-2', $handler2, 'user-2');
    $this->manager->addConnection('user-1', 'conn-1');

    $this->manager->removeConnection('user-1');

    expect($error1Fired)->toBeTrue();
    expect($error2Fired)->toBeFalse();

    // user-2's request should still be there
    expect($this->manager->getPendingRequest('req-2'))->toBe($handler2);
});

// --- Token claim bookkeeping (cid + exp) for managed CLI bridges ---

test('addConnection records the token exp and cid claims when provided', function () {
    $expiresAt = time() + 3600;
    $this->manager->addConnection('user-1', 'conn-1', null, [], $expiresAt, 99);

    expect($this->manager->getTokenExpiresAt('user-1'))->toBe($expiresAt);
    expect($this->manager->getTokenCid('user-1'))->toBe(99);
});

test('addConnection without token claims leaves cid and exp null', function () {
    $this->manager->addConnection('user-1', 'conn-1');

    expect($this->manager->getTokenExpiresAt('user-1'))->toBeNull();
    expect($this->manager->getTokenCid('user-1'))->toBeNull();
});

test('setTokenExpiresAt updates the recorded expiry for an existing connection', function () {
    $this->manager->addConnection('user-1', 'conn-1', null, [], time() + 60, 99);

    $next = time() + 86400;
    $this->manager->setTokenExpiresAt('user-1', $next);

    expect($this->manager->getTokenExpiresAt('user-1'))->toBe($next);
});

test('setTokenExpiresAt is a no-op for an unknown user', function () {
    // Must not throw, must not leak a partial row.
    $this->manager->setTokenExpiresAt('unknown-user', time() + 3600);

    expect($this->manager->hasConnection('unknown-user'))->toBeFalse();
});

// --- disconnectUser: forced drop on regenerate/delete ---

test('disconnectUser closes the live connection with CLOSE_INVALID_TOKEN and removes it', function () {
    $closedWith = null;
    $conn = Mockery::mock(SendableConnection::class);
    $conn->shouldReceive('close')
        ->once()
        ->andReturnUsing(function (int $code) use (&$closedWith) {
            $closedWith = $code;
        });

    $this->manager->addConnection('user-1', 'conn-1', $conn);

    expect($this->manager->disconnectUser('user-1'))->toBeTrue();
    expect($closedWith)->toBe(SendableConnection::CLOSE_INVALID_TOKEN);
    expect($this->manager->hasConnection('user-1'))->toBeFalse();
});

test('disconnectUser returns false when no connection is present', function () {
    expect($this->manager->disconnectUser('nobody'))->toBeFalse();
});

test('disconnectUser swallows close() failures and still removes the connection', function () {
    $conn = Mockery::mock(SendableConnection::class);
    $conn->shouldReceive('close')
        ->once()
        ->andThrow(new RuntimeException('stream already closed'));

    $this->manager->addConnection('user-1', 'conn-1', $conn);

    expect($this->manager->disconnectUser('user-1'))->toBeTrue();
    expect($this->manager->hasConnection('user-1'))->toBeFalse();
});

// --- D09710: a reliable_delivery bridge's turns survive a short drop ---

function reliableTurn(BridgeConnectionManager $manager, string $requestId, ?string &$errorCode = null): StreamHandler
{
    $handler = createTestStreamHandler();
    $handler->onError(function (string $code) use (&$errorCode) {
        $errorCode = $code;
    });
    $manager->registerPendingRequest($requestId, $handler, 'user-1');

    return $handler;
}

function connectReliable(BridgeConnectionManager $manager, string $connectionId): void
{
    $manager->addConnection('user-1', $connectionId);
    $manager->setBridgeInfo('user-1', ['capabilities' => ['reliable_delivery']]);
}

test('a reliable bridge dropping detaches its turns instead of failing them', function () {
    connectReliable($this->manager, 'conn-1');
    $code = null;
    reliableTurn($this->manager, 'req-1', $code);

    $this->manager->removeConnection('user-1', 'websocket_closed');

    expect($code)->toBeNull()
        ->and($this->manager->getPendingRequest('req-1'))->not->toBeNull()
        ->and($this->manager->isDetached('req-1'))->toBeTrue()
        ->and($this->manager->detachedRequestIdsForUser('user-1'))->toBe(['req-1'])
        ->and($this->manager->pendingRequestIdsForUser('user-1'))->toBe(['req-1']);
});

test('a reliable bridge replaced by a new connection detaches too, no bridge_reconnecting', function () {
    connectReliable($this->manager, 'conn-old');
    $code = null;
    reliableTurn($this->manager, 'req-1', $code);

    $this->manager->addConnection('user-1', 'conn-new');

    expect($code)->toBeNull()
        ->and($this->manager->isDetached('req-1'))->toBeTrue();
});

test('a listed detached turn is re-attached; an unlisted one fails at once with bridge_disconnected', function () {
    connectReliable($this->manager, 'conn-1');
    $code1 = null;
    $code2 = null;
    reliableTurn($this->manager, 'req-1', $code1);
    reliableTurn($this->manager, 'req-2', $code2);
    $this->manager->removeConnection('user-1', 'websocket_closed');

    connectReliable($this->manager, 'conn-2');
    $result = $this->manager->reattachRunningTurns('user-1', ['req-1', 'req-unknown']);

    expect($result)->toBe(['reattached' => ['req-1'], 'failed' => ['req-2']])
        ->and($code1)->toBeNull()
        ->and($this->manager->isDetached('req-1'))->toBeFalse()
        ->and($code2)->toBe('bridge_disconnected')
        ->and($this->manager->getPendingRequest('req-2'))->toBeNull()
        ->and($this->manager->takeDroppedRequestOwner('req-2'))->toBe('user-1');

    Event::assertDispatched(TurnLostAfterDrop::class, fn (TurnLostAfterDrop $e) => $e->requestId === 'req-2'
        && $e->userId === 'user-1' && $e->conversationId === 'test-conv' && $e->reason === TurnLostAfterDrop::NOT_LISTED);
    Event::assertDispatchedTimes(TurnLostAfterDrop::class, 1);
});

test('a detached turn fails with bridge_disconnected when the grace period passes', function () {
    config(['ai-bridge.websocket.reconnect_grace_seconds' => 120]);
    connectReliable($this->manager, 'conn-1');
    $code = null;
    reliableTurn($this->manager, 'req-1', $code);
    $this->manager->removeConnection('user-1', 'websocket_closed');

    expect($this->manager->expireDetachedRequests(time() + 119))->toBe(0)
        ->and($code)->toBeNull();

    expect($this->manager->expireDetachedRequests(time() + 121))->toBe(1)
        ->and($code)->toBe('bridge_disconnected')
        ->and($this->manager->getPendingRequest('req-1'))->toBeNull();

    Event::assertDispatched(TurnLostAfterDrop::class, fn (TurnLostAfterDrop $e) => $e->reason === TurnLostAfterDrop::GRACE_EXPIRED);
});

test('a re-attached turn is not failed when the grace period would have passed', function () {
    connectReliable($this->manager, 'conn-1');
    $code = null;
    reliableTurn($this->manager, 'req-1', $code);
    $this->manager->removeConnection('user-1', 'websocket_closed');
    connectReliable($this->manager, 'conn-2');
    $this->manager->reattachRunningTurns('user-1', ['req-1']);

    expect($this->manager->expireDetachedRequests(time() + 10_000))->toBe(0)
        ->and($code)->toBeNull();
    Event::assertNotDispatched(TurnLostAfterDrop::class);
});

test('an old bridge dropping still fails its turns at once and fires no TurnLostAfterDrop', function () {
    $this->manager->addConnection('user-1', 'conn-1');
    $code = null;
    reliableTurn($this->manager, 'req-1', $code);

    $this->manager->removeConnection('user-1', 'websocket_closed');

    expect($code)->toBe('bridge_disconnected')
        ->and($this->manager->getPendingRequest('req-1'))->toBeNull();
    Event::assertNotDispatched(TurnLostAfterDrop::class);
});

test('acceptSeq stores only the next seq, drops duplicates and refuses gaps', function () {
    reliableTurn($this->manager, 'req-1');

    expect($this->manager->acceptSeq('req-1', 2))->toBe(BridgeConnectionManager::SEQ_GAP)
        ->and($this->manager->acceptSeq('req-1', 1))->toBe(BridgeConnectionManager::SEQ_STORE);
    $this->manager->commitSeq('req-1', 1);
    expect($this->manager->acceptSeq('req-1', 1))->toBe(BridgeConnectionManager::SEQ_DUPLICATE)
        ->and($this->manager->acceptSeq('req-1', 2))->toBe(BridgeConnectionManager::SEQ_STORE);
    $this->manager->commitSeq('req-1', 2);
    expect($this->manager->lastStoredSeq('req-1'))->toBe(2)
        ->and($this->manager->acceptSeq('unknown', 1))->toBe(BridgeConnectionManager::SEQ_GAP);

    // A session_lost re-issue may number from 1 again.
    $this->manager->allowSeqRestart('req-1');
    expect($this->manager->acceptSeq('req-1', 1))->toBe(BridgeConnectionManager::SEQ_STORE);
    $this->manager->commitSeq('req-1', 1);
    expect($this->manager->acceptSeq('req-1', 1))->toBe(BridgeConnectionManager::SEQ_DUPLICATE);
});

test('acceptSeq only judges a frame: until commitSeq it is not counted as stored', function () {
    reliableTurn($this->manager, 'req-1');

    expect($this->manager->acceptSeq('req-1', 1))->toBe(BridgeConnectionManager::SEQ_STORE)
        ->and($this->manager->acceptSeq('req-1', 1))->toBe(BridgeConnectionManager::SEQ_STORE)
        ->and($this->manager->lastStoredSeq('req-1'))->toBe(0);
});

// --- D09710 rework: what a drop keeps, and what it does not ---

test('a reconnect that drops before its hello keeps the turns held for the earlier drop (#14258)', function () {
    connectReliable($this->manager, 'conn-1');
    $code = null;
    reliableTurn($this->manager, 'req-1', $code);
    $this->manager->removeConnection('user-1', 'websocket_closed');

    // The bridge reconnects, and that socket breaks before it said hello.
    $this->manager->addConnection('user-1', 'conn-2');
    $this->manager->removeConnection('user-1', 'websocket_closed');

    expect($code)->toBeNull()
        ->and($this->manager->isDetached('req-1'))->toBeTrue()
        ->and($this->manager->isAwaitingReconnect('user-1'))->toBeTrue();

    connectReliable($this->manager, 'conn-3');
    expect($this->manager->reattachRunningTurns('user-1', ['req-1']))->toBe(['reattached' => ['req-1'], 'failed' => []])
        ->and($code)->toBeNull();
});

test('a deliberate close fails a reliable bridge\'s turns at once instead of detaching them (#14259, #14278)', function (string $how) {
    connectReliable($this->manager, 'conn-1');
    $code = null;
    reliableTurn($this->manager, 'req-1', $code);
    $gone = [];
    $this->manager->onUserGone(function (string $u) use (&$gone) {
        $gone[] = $u;
    });

    $how === 'revoked'
        ? $this->manager->disconnectUser('user-1')
        : $this->manager->removeConnection('user-1', 'bridge_shutdown');

    expect($code)->toBe('bridge_disconnected')
        ->and($this->manager->getPendingRequest('req-1'))->toBeNull()
        ->and($this->manager->isAwaitingReconnect('user-1'))->toBeFalse()
        ->and($gone)->toBe(['user-1']);
    Event::assertNotDispatched(TurnLostAfterDrop::class);
})->with(['revoked', 'bridge shut down with a normal close']);

test('a deliberate close while an earlier drop is waited out fails the detached turns too', function () {
    connectReliable($this->manager, 'conn-1');
    $code = null;
    reliableTurn($this->manager, 'req-1', $code);
    $this->manager->removeConnection('user-1', 'websocket_closed');
    $this->manager->addConnection('user-1', 'conn-2');

    $this->manager->disconnectUser('user-1');

    expect($code)->toBe('bridge_disconnected')
        ->and($this->manager->isAwaitingReconnect('user-1'))->toBeFalse();
});

test('a turn lost after a drop says why, in plain words, per reason (#14263)', function (string $reason, string $expected) {
    config(['ai-bridge.websocket.reconnect_grace_seconds' => 120]);
    connectReliable($this->manager, 'conn-1');
    $handler = reliableTurn($this->manager, 'req-1');
    $message = null;
    $handler->onError(function (string $code, string $text) use (&$message) {
        $message = $text;
    });
    $this->manager->removeConnection('user-1', 'websocket_closed');

    if ($reason === TurnLostAfterDrop::NOT_LISTED) {
        connectReliable($this->manager, 'conn-2');
        $this->manager->reattachRunningTurns('user-1', []);
    } else {
        $this->manager->expireDetachedRequests(time() + 121);
    }

    expect($message)->toBe($expected);
})->with([
    'not listed after the reconnect' => [TurnLostAfterDrop::NOT_LISTED, 'The connection to your machine dropped. It came back, but it was no longer working on this reply (it may have restarted), so the reply stopped here and the rest of it was not saved. Send your message again to continue.'],
    'not back in time' => [TurnLostAfterDrop::GRACE_EXPIRED, 'The connection to your machine dropped and it did not come back within 120 seconds, so the reply stopped here and the rest of it was not saved. Send your message again to continue.'],
]);

test('the finished-turn memory keeps the newest 500 turns (#14262)', function () {
    for ($i = 1; $i <= 501; $i++) {
        $this->manager->rememberFinishedRequest("req-{$i}", 'user-1', $i);
    }

    expect($this->manager->finishedRequestSeq('req-1', 'user-1'))->toBeNull()
        ->and($this->manager->finishedRequestSeq('req-2', 'user-1'))->toBe(2)
        ->and($this->manager->finishedRequestSeq('req-501', 'user-1'))->toBe(501)
        ->and($this->manager->finishedRequestSeq('req-501', 'user-2'))->toBeNull();
});

/** A reliable bridge of user-1 that records what is written to it. */
function heldRig(object $test): object
{
    $rig = new stdClass();
    $rig->sent = [];
    $test->manager->setSendCallback(function ($c, array $payload) use ($rig): bool {
        $rig->sent[] = $payload;

        return true;
    });
    connectReliable($test->manager, 'conn-1');

    return $rig;
}

test('work for a machine on a short drop is held and sent after its next hello (#14256)', function () {
    $rig = heldRig($this);
    $this->manager->removeConnection('user-1', 'websocket_closed');
    $sentAt = [];

    expect($this->manager->canReach('user-1'))->toBeTrue()
        ->and($this->manager->reachableBridgeSupports('user-1', 'reliable_delivery'))->toBeTrue()
        ->and($this->manager->sendOrHold('user-1', ['type' => 'usage_request', 'id' => 'u-1'], function () use (&$sentAt) {
            $sentAt[] = 'u-1';
        }))->toBeTrue()
        ->and($rig->sent)->toBe([]);

    // Reconnected, but not until the hello has been answered.
    connectReliable($this->manager, 'conn-2');
    expect($this->manager->flushHeldFrames('user-1'))->toBe(0);
    $this->manager->reattachRunningTurns('user-1', []);

    expect($this->manager->flushHeldFrames('user-1'))->toBe(1)
        ->and($rig->sent)->toBe([['type' => 'usage_request', 'id' => 'u-1']])
        ->and($sentAt)->toBe(['u-1'])
        ->and($this->manager->flushHeldFrames('user-1'))->toBe(0);

    // Connected again: sent straight away.
    $this->manager->sendOrHold('user-1', ['type' => 'turn_input', 'request_id' => 'r', 'message_id' => 'm']);
    expect($rig->sent)->toHaveCount(2);
});

test('a usage question and a mid-turn message already out are put again after the reconnect, not failed (#14256, #14274)', function () {
    $rig = heldRig($this);
    $answers = [];
    $usageFrame = ['type' => 'usage_request', 'id' => 'u-1'];
    $inputFrame = ['type' => 'turn_input', 'request_id' => 'req-1', 'message_id' => 'm-1', 'content' => 'hi'];
    $this->manager->registerPendingUsage('u-1', 'user-1', function (array $a) use (&$answers) {
        $answers[] = $a;
    }, $usageFrame);
    $this->manager->registerPendingTurnInput('req-1', 'm-1', 'user-1', function (array $a) use (&$answers) {
        $answers[] = $a;
    }, $inputFrame);

    $this->manager->removeConnection('user-1', 'websocket_closed');
    expect($answers)->toBe([]);

    // The bridge that comes back takes input, as the one that dropped did.
    connectReliable($this->manager, 'conn-2');
    $this->manager->setBridgeInfo('user-1', ['capabilities' => ['reliable_delivery', 'turn_input']]);
    $this->manager->reattachRunningTurns('user-1', []);
    $this->manager->flushHeldFrames('user-1');

    expect($rig->sent)->toBe([$usageFrame, $inputFrame])
        ->and($this->manager->hasPendingTurnInput('req-1', 'm-1'))->toBeTrue();

    $this->manager->resolvePendingTurnInput('req-1', 'm-1', 'user-1', ['status' => 'accepted']);
    expect($answers)->toBe([['status' => 'accepted']]);
});

test('an exchange already out when the machine drops fails at once; one asked during the drop waits', function () {
    $rig = heldRig($this);
    $answers = [];
    $this->manager->registerPendingExchange('mcp:1', 'user-1', function (array $a) use (&$answers) {
        $answers['mcp:1'] = $a;
    }, ['ok' => false, 'code' => 'not_connected']);

    $this->manager->removeConnection('user-1', 'websocket_closed');
    expect($answers)->toBe(['mcp:1' => ['ok' => false, 'code' => 'not_connected']]);

    $this->manager->registerPendingExchange('mcp:2', 'user-1', function (array $a) use (&$answers) {
        $answers['mcp:2'] = $a;
    }, ['ok' => false, 'code' => 'not_connected']);
    $this->manager->sendOrHold('user-1', ['type' => 'mcp_request', 'id' => '2']);

    expect($answers)->not->toHaveKey('mcp:2')
        ->and($this->manager->hasPendingExchange('mcp:2'))->toBeTrue();
});

test('when the grace period ends without the machine, everything that waited fails, and only then (#14256)', function () {
    config(['ai-bridge.websocket.reconnect_grace_seconds' => 120]);
    heldRig($this);
    $answers = [];
    $gone = [];
    $this->manager->onUserGone(function (string $u) use (&$gone) {
        $gone[] = $u;
    });
    $this->manager->registerPendingUsage('u-1', 'user-1', function (array $a) use (&$answers) {
        $answers['usage'] = $a;
    }, ['type' => 'usage_request', 'id' => 'u-1']);
    $this->manager->registerPendingTurnInput('req-1', 'm-1', 'user-1', function (array $a) use (&$answers) {
        $answers['input'] = $a;
    }, ['type' => 'turn_input', 'request_id' => 'req-1', 'message_id' => 'm-1', 'content' => 'hi']);

    $this->manager->removeConnection('user-1', 'websocket_closed');
    $this->manager->registerPendingExchange('mcp:2', 'user-1', function (array $a) use (&$answers) {
        $answers['mcp'] = $a;
    }, ['ok' => false, 'code' => 'not_connected']);
    $this->manager->sendOrHold('user-1', ['type' => 'mcp_request', 'id' => '2']);

    $this->manager->expireDetachedRequests(time() + 119);
    expect($answers)->toBe([])->and($gone)->toBe([]);

    $this->manager->expireDetachedRequests(time() + 121);
    expect($answers)->toBe([
        'usage' => ['ok' => false, 'reason' => 'not_connected'],
        'input' => ['status' => 'rejected', 'reason' => 'connection_lost'],
        'mcp' => ['ok' => false, 'code' => 'not_connected'],
    ])
        ->and($gone)->toBe(['user-1'])
        ->and($this->manager->canReach('user-1'))->toBeFalse()
        ->and($this->manager->sendOrHold('user-1', ['type' => 'usage_request', 'id' => 'u-2']))->toBeFalse();
});

test('an old bridge dropping still fails a mid-turn message with no_answer at once', function () {
    $this->manager->addConnection('user-1', 'conn-1');
    $answer = null;
    $this->manager->registerPendingTurnInput('req-1', 'm-1', 'user-1', function (array $a) use (&$answer) {
        $answer = $a;
    }, ['type' => 'turn_input', 'request_id' => 'req-1', 'message_id' => 'm-1', 'content' => 'hi']);

    $this->manager->removeConnection('user-1', 'websocket_closed');

    expect($answer)->toBe(['status' => 'rejected', 'reason' => 'no_answer'])
        ->and($this->manager->canReach('user-1'))->toBeFalse();
});

test('a mid-turn message held through a drop keeps its own refusal when its write after the hello fails and the machine drops again (CodeRabbit 4537573159)', function () {
    $rig = heldRig($this);
    $this->manager->removeConnection('user-1', 'websocket_closed');

    // Typed during the drop: held, never written, so a bridge that cannot take it means send_failed.
    $answer = null;
    $frame = ['type' => 'turn_input', 'request_id' => 'req-1', 'message_id' => 'm-1', 'content' => 'hi'];
    $this->manager->registerPendingTurnInput('req-1', 'm-1', 'user-1', function (array $a) use (&$answer) {
        $answer = $a;
    }, $frame);
    $key = BridgeConnectionManager::turnInputHoldKey('req-1', 'm-1');
    $this->manager->sendOrHold('user-1', $frame, null, $key, 'turn_input', fn () => $this->manager->resolvePendingTurnInput('req-1', 'm-1', 'user-1', ['status' => 'rejected', 'reason' => 'send_failed']));

    // The bridge comes back, but the held write fails: still held, still never written.
    $this->manager->setSendCallback(fn () => false);
    connectReliable($this->manager, 'conn-2');
    $this->manager->setBridgeInfo('user-1', ['capabilities' => ['reliable_delivery', 'turn_input']]);
    $this->manager->reattachRunningTurns('user-1', []);
    $this->manager->flushHeldFrames('user-1');
    expect($this->manager->isHeldFrame('user-1', $key))->toBeTrue();

    // It drops again, and an older bridge without turn_input takes its place.
    $this->manager->removeConnection('user-1', 'websocket_closed');
    $this->manager->setSendCallback(function ($c, array $payload) use ($rig): bool {
        $rig->sent[] = $payload;

        return true;
    });
    connectReliable($this->manager, 'conn-3');
    $this->manager->reattachRunningTurns('user-1', []);
    $this->manager->flushHeldFrames('user-1');

    // MUTATION: overwrite the held entry on the second drop and this reads no_answer.
    expect($answer)->toBe(['status' => 'rejected', 'reason' => 'send_failed'])
        ->and($rig->sent)->toBe([]);
});
