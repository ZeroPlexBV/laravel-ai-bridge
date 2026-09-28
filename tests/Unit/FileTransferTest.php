<?php

declare(strict_types=1);

use Evenement\EventEmitter;
use Illuminate\Support\Facades\Event;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Server\BridgeWebSocketServer;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\Transfers\ChunkedDecoder;
use Tetrix\AiBridge\Transfers\TransferHub;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/*
|--------------------------------------------------------------------------
| Streamed uploads and downloads through the serve process
|--------------------------------------------------------------------------
|
| The worker (PHP-FPM) and the machine each hold one socket to the serve
| process; the hub pipes bytes between them. These tests drive the hub with
| in-memory sockets: what each side wrote, and whether each is paused.
|
*/

/** An in-memory socket: records what was written to it, and can be told it is full. */
final class FakeSocket extends EventEmitter implements ConnectionInterface
{
    public string $written = '';

    public bool $paused = false;

    public bool $ended = false;

    public bool $closed = false;

    /** Return false from write() (a full buffer) until drained. */
    public bool $full = false;

    public function write($data): bool
    {
        $this->written .= $data;

        return ! $this->full;
    }

    public function drain(): void
    {
        $this->full = false;
        $this->emit('drain');
    }

    public function end($data = null): void
    {
        $this->ended = true;
    }

    public function close(): void
    {
        if (! $this->closed) {
            $this->closed = true;
            $this->emit('close');
        }
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    public function isReadable(): bool
    {
        return ! $this->closed;
    }

    public function isWritable(): bool
    {
        return ! $this->closed;
    }

    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        return $dest;
    }

    public function getRemoteAddress(): ?string
    {
        return null;
    }

    public function getLocalAddress(): ?string
    {
        return null;
    }

    /** @return array{0: int, 1: array<string, string>, 2: string} status, headers, body */
    public function response(): array
    {
        [$head, $body] = array_pad(explode("\r\n\r\n", $this->written, 2), 2, '');
        $lines = explode("\r\n", $head);
        preg_match('#^HTTP/1\.1 (\d+)#', (string) array_shift($lines), $m);
        $headers = [];
        foreach ($lines as $line) {
            [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($k))] = trim($v);
        }

        return [(int) ($m[1] ?? 0), $headers, $body];
    }

    public function json(): ?array
    {
        return json_decode($this->response()[2], true);
    }
}

function transferRig(array $hello = ['file_uploads' => true, 'file_downloads' => true]): object
{
    Event::fake();
    config()->set('ai-bridge.server.public_url', 'wss://studio.test/api/ai-bridge/ws');

    $rig = new stdClass();
    $rig->manager = new BridgeConnectionManager();
    $rig->loop = new StreamSelectLoop();
    $rig->hub = new TransferHub($rig->manager, app(TokenManager::class), $rig->loop);
    $rig->handler = new MessageHandler($rig->manager, app(TokenManager::class), new ToolRegistry());
    $rig->handler->setTransferHub($rig->hub);
    $rig->manager->onUserGone(fn (string $u) => $rig->hub->userGone($u));

    $rig->sent = [];
    $rig->manager->setSendCallback(function (mixed $c, array $payload) use ($rig): bool {
        $rig->sent[] = $payload;

        return true;
    });
    $rig->manager->addConnection('user-1', 'conn-1', 'sock-1');
    $rig->manager->addConnection('user-2', 'conn-2', 'sock-2');
    $rig->manager->setBridgeInfo('user-1', MessageHandler::bridgeInfoFromHello($hello + [
        'attachment_limits' => ['max_file_bytes' => 1000, 'max_total_bytes' => 5000, 'max_count' => 5],
    ]));
    $rig->bridgeToken = app(TokenManager::class)->generate('user-1');

    return $rig;
}

function frame(object $rig, string $type): ?array
{
    foreach ($rig->sent as $f) {
        if ($f['type'] === $type) {
            return $f;
        }
    }

    return null;
}

/** Start an upload of $size bytes, of which $early came with the headers. */
function startUpload(object $rig, int $size, string $early = '', string $user = 'user-1'): FakeSocket
{
    $worker = new FakeSocket();
    $rig->hub->startUpload($worker, $user, [
        'content-length' => (string) $size,
        'x-file-name' => rawurlencode('report ü.pdf'),
        'x-working-dir' => rawurlencode('/work/repo'),
        'x-file-type' => 'application/pdf; charset=binary',
    ], $early);

    return $worker;
}

function machineGets(object $rig, string $id, ?string $token = null): FakeSocket
{
    $machine = new FakeSocket();
    $rig->hub->machineRequest($machine, 'GET', $id, ['authorization' => 'Bearer '.($token ?? $rig->bridgeToken)], '');

    return $machine;
}

function machineSays(object $rig, array $frame, string $conn = 'conn-1'): void
{
    $rig->handler->handleMessage($conn, null, json_encode($frame));
}

// --- Upload ------------------------------------------------------------------

it('offers an upload to the machine with a one-time URL on the connected origin, holding the worker', function () {
    $rig = transferRig();
    $worker = startUpload($rig, 10, 'abc');

    $offer = frame($rig, MessageTypes::UPLOAD_OFFER);

    expect($offer)->toMatchArray([
        'working_dir' => '/work/repo',
        'name' => 'report ü.pdf',
        'mime_type' => 'application/pdf',
        'size' => 10,
    ])
        ->and($offer['url'])->toBe('https://studio.test/api/ai-bridge/ws?transfer='.$offer['id'])
        ->and($worker->paused)->toBeTrue()
        ->and($worker->written)->toBe('');
});

it('pipes the bytes to the machine, says what passed, and answers the worker with the machine\'s record', function () {
    $rig = transferRig();
    $worker = startUpload($rig, 10, 'abc');
    $id = frame($rig, MessageTypes::UPLOAD_OFFER)['id'];

    $machine = machineGets($rig, $id);
    expect($machine->written)->toStartWith("HTTP/1.1 200 OK\r\n")
        ->and($machine->written)->toContain('Content-Length: 10')
        ->and($worker->paused)->toBeFalse();

    $worker->emit('data', ['defg']);
    $worker->emit('data', ['hij']);

    $sha = hash('sha256', 'abcdefghij');
    expect($machine->response()[2])->toBe('abcdefghij')
        ->and($machine->ended)->toBeTrue()
        ->and(frame($rig, MessageTypes::UPLOAD_SENT))->toBe(['type' => 'upload_sent', 'id' => $id, 'size' => 10, 'sha256' => $sha]);

    machineSays($rig, ['type' => 'upload_done', 'id' => $id, 'ok' => true, 'path' => '/work/repo/file-uploads/report ü.pdf',
        'name' => 'report ü.pdf', 'size' => 10, 'sha256' => $sha, 'file_id' => 'f-1']);

    expect($worker->response()[0])->toBe(200)
        ->and($worker->json())->toBe(['ok' => true, 'path' => '/work/repo/file-uploads/report ü.pdf', 'name' => 'report ü.pdf',
            'size' => 10, 'sha256' => $sha, 'file_id' => 'f-1'])
        ->and($rig->hub->inFlight())->toBe(0);
});

it('stops reading the worker while the machine is behind, and resumes on drain', function () {
    $rig = transferRig();
    $worker = startUpload($rig, 6);
    $id = frame($rig, MessageTypes::UPLOAD_OFFER)['id'];
    $machine = machineGets($rig, $id);

    $machine->full = true;
    $worker->emit('data', ['abc']);
    expect($worker->paused)->toBeTrue();

    $machine->drain();
    expect($worker->paused)->toBeFalse();
});

it('refuses a machine that is not the one the upload was offered to', function () {
    $rig = transferRig();
    startUpload($rig, 3);
    $id = frame($rig, MessageTypes::UPLOAD_OFFER)['id'];

    $other = machineGets($rig, $id, app(TokenManager::class)->generate('user-2'));
    $relay = machineGets($rig, $id, app(TokenManager::class)->generate('user-1', ['scope' => TokenManager::INTERNAL_RELAY_SCOPE]));
    $nobody = machineGets($rig, 'not-an-id');

    expect($other->response()[0])->toBe(404)
        ->and($relay->response()[0])->toBe(401)
        ->and($nobody->response()[0])->toBe(404);

    // Still waiting for the right one, and single use once it came.
    expect(machineGets($rig, $id)->response()[0])->toBe(200)
        ->and(machineGets($rig, $id)->response()[0])->toBe(404);
});

it('passes on a machine refusal and does not tell it to abort what it already refused', function () {
    $rig = transferRig();
    $worker = startUpload($rig, 3);
    $id = frame($rig, MessageTypes::UPLOAD_OFFER)['id'];

    machineSays($rig, ['type' => 'upload_done', 'id' => $id, 'ok' => false, 'code' => 'working_dir_not_allowed', 'error' => 'not allowed']);

    expect($worker->response()[0])->toBe(422)
        ->and($worker->json())->toMatchArray(['ok' => false, 'code' => 'working_dir_not_allowed'])
        ->and(frame($rig, MessageTypes::UPLOAD_ABORT))->toBeNull();
});

it('refuses a digest that does not match what passed', function () {
    $rig = transferRig();
    $worker = startUpload($rig, 3, 'abc');
    $id = frame($rig, MessageTypes::UPLOAD_OFFER)['id'];
    machineGets($rig, $id);

    machineSays($rig, ['type' => 'upload_done', 'id' => $id, 'ok' => true, 'path' => '/x', 'name' => 'x', 'size' => 3, 'sha256' => str_repeat('0', 64), 'file_id' => 'f']);

    expect($worker->response()[0])->toBe(502)
        ->and($worker->json()['code'])->toBe('upload_mismatch')
        ->and(frame($rig, MessageTypes::UPLOAD_ABORT))->not->toBeNull();
});

it('ignores upload_done from another user\'s machine', function () {
    $rig = transferRig();
    $worker = startUpload($rig, 3);
    $id = frame($rig, MessageTypes::UPLOAD_OFFER)['id'];

    machineSays($rig, ['type' => 'upload_done', 'id' => $id, 'ok' => false, 'code' => 'x', 'error' => 'y'], 'conn-2');

    expect($worker->written)->toBe('')->and($rig->hub->inFlight())->toBe(1);
});

it('aborts at the machine when the browser goes away mid-upload', function () {
    $rig = transferRig();
    $worker = startUpload($rig, 10, 'abc');
    $id = frame($rig, MessageTypes::UPLOAD_OFFER)['id'];
    $machine = machineGets($rig, $id);

    $worker->close();

    expect(frame($rig, MessageTypes::UPLOAD_ABORT)['id'])->toBe($id)
        ->and($machine->closed)->toBeTrue()
        ->and($rig->hub->inFlight())->toBe(0);
});

it('refuses more bytes than declared', function () {
    $rig = transferRig();
    $worker = startUpload($rig, 3);
    machineGets($rig, frame($rig, MessageTypes::UPLOAD_OFFER)['id']);

    $worker->emit('data', ['abcd']);

    expect($worker->json()['code'])->toBe('size_mismatch');
});

it('ends every transfer of a machine that goes away, now rather than on a timer', function () {
    $rig = transferRig();
    $worker = startUpload($rig, 3);

    $rig->manager->removeConnection('user-1', 'transport_closed');

    expect($worker->json()['code'])->toBe('machine_gone')->and($rig->hub->inFlight())->toBe(0);
});

it('refuses before offering when the machine cannot take it', function (array $hello, string $user, int $size, int $status, string $code) {
    $rig = transferRig($hello);
    $worker = startUpload($rig, $size, '', $user);

    expect($worker->response()[0])->toBe($status)
        ->and($worker->json()['code'])->toBe($code)
        ->and(frame($rig, MessageTypes::UPLOAD_OFFER))->toBeNull();
})->with([
    'too old' => [[], 'user-1', 3, 409, 'unsupported'],
    'not connected' => [['file_uploads' => true], 'user-9', 3, 409, 'not_connected'],
    'over the machine\'s cap' => [['file_uploads' => true], 'user-1', 1001, 413, 'upload_too_large'],
]);

it('gives up when the machine never comes for the bytes', function () {
    $rig = transferRig();
    $worker = startUpload($rig, 3);
    $id = frame($rig, MessageTypes::UPLOAD_OFFER)['id'];

    // Fire the pickup timer.
    $timer = (new ReflectionProperty(TransferHub::class, 'uploads'))->getValue($rig->hub)[$id]['timer'];
    ($timer->getCallback())();

    expect($worker->response()[0])->toBe(504)
        ->and($worker->json()['code'])->toBe('pickup_timeout')
        ->and(frame($rig, MessageTypes::UPLOAD_ABORT)['id'])->toBe($id);
});

// --- Download ------------------------------------------------------------------

function startDownload(object $rig, array $body = ['file_id' => 'f-1', 'range' => 'bytes=0-4']): FakeSocket
{
    $worker = new FakeSocket();
    $rig->hub->startDownload($worker, 'user-1', $body);

    return $worker;
}

it('asks the machine for a file by its id with the browser\'s Range, and answers the worker with its headers', function () {
    $rig = transferRig();
    $worker = startDownload($rig);
    $ask = frame($rig, MessageTypes::FILE_READ);

    expect($ask)->toMatchArray(['file_id' => 'f-1', 'range' => 'bytes=0-4'])
        ->and($ask['url'])->toBe('https://studio.test/api/ai-bridge/ws?transfer='.$ask['id']);

    machineSays($rig, ['type' => 'file_read_result', 'id' => $ask['id'], 'ok' => true, 'size' => 100, 'status' => 206, 'start' => 0, 'end' => 4]);

    [$status, $headers] = $worker->response();
    expect($status)->toBe(200)
        ->and(json_decode($headers['x-transfer-result'], true))->toBe(['ok' => true, 'size' => 100, 'status' => 206, 'start' => 0, 'end' => 4, 'length' => 5])
        ->and($worker->ended)->toBeFalse();
});

it('pipes the machine\'s POST to the worker, chunked or not, and answers the machine', function (array $headers, array $pieces) {
    $rig = transferRig();
    $worker = startDownload($rig);
    $id = frame($rig, MessageTypes::FILE_READ)['id'];
    machineSays($rig, ['type' => 'file_read_result', 'id' => $id, 'ok' => true, 'size' => 100, 'status' => 206, 'start' => 0, 'end' => 4]);

    $machine = new FakeSocket();
    $rig->hub->machineRequest($machine, 'POST', $id, $headers + ['authorization' => 'Bearer '.$rig->bridgeToken], array_shift($pieces));
    foreach ($pieces as $p) {
        $machine->emit('data', [$p]);
    }

    expect($worker->response()[2])->toBe('hello')
        ->and($worker->ended)->toBeTrue()
        ->and($machine->response()[0])->toBe(200)
        ->and($rig->hub->inFlight())->toBe(0);
})->with([
    'content-length' => [['content-length' => '5'], ['he', 'llo']],
    'chunked' => [['transfer-encoding' => 'chunked'], ["2\r\nhe\r\n", "3\r\nllo\r\n0\r\n\r\n"]],
    'neither' => [[], ['hel', 'lo']],
]);

it('pauses the machine while the browser is behind', function () {
    $rig = transferRig();
    $worker = startDownload($rig);
    $id = frame($rig, MessageTypes::FILE_READ)['id'];
    machineSays($rig, ['type' => 'file_read_result', 'id' => $id, 'ok' => true, 'size' => 100, 'status' => 206, 'start' => 0, 'end' => 4]);
    $machine = new FakeSocket();
    $rig->hub->machineRequest($machine, 'POST', $id, ['content-length' => '5', 'authorization' => 'Bearer '.$rig->bridgeToken], '');

    $worker->full = true;
    $machine->emit('data', ['he']);
    expect($machine->paused)->toBeTrue();

    $worker->drain();
    expect($machine->paused)->toBeFalse();
});

it('passes on the machine\'s refusal as a status the browser understands', function (string $code, int $status) {
    $rig = transferRig();
    $worker = startDownload($rig);
    $id = frame($rig, MessageTypes::FILE_READ)['id'];

    machineSays($rig, ['type' => 'file_read_result', 'id' => $id, 'ok' => false, 'code' => $code, 'error' => 'no']);

    expect($worker->response()[0])->toBe($status)->and($worker->json()['code'])->toBe($code);
})->with([['file_gone', 410], ['file_unknown', 410], ['file_changed', 409], ['file_refused', 502]]);

it('ends at once for a HEAD, a 416 or an empty range, without waiting for bytes', function (array $body, array $result) {
    $rig = transferRig();
    $worker = startDownload($rig, $body);
    $id = frame($rig, MessageTypes::FILE_READ)['id'];

    machineSays($rig, ['type' => 'file_read_result', 'id' => $id, 'ok' => true] + $result);

    expect($worker->ended)->toBeTrue()->and($rig->hub->inFlight())->toBe(0);
})->with([
    'head' => [['file_id' => 'f', 'head' => true], ['size' => 9, 'status' => 200, 'start' => 0, 'end' => 8]],
    '416' => [['file_id' => 'f', 'range' => 'bytes=50-'], ['size' => 9, 'status' => 416, 'start' => 0, 'end' => -1]],
    'empty file' => [['file_id' => 'f'], ['size' => 0, 'status' => 200, 'start' => 0, 'end' => -1]],
]);

it('tells the machine to stop when the browser goes away', function () {
    $rig = transferRig();
    $worker = startDownload($rig);
    $id = frame($rig, MessageTypes::FILE_READ)['id'];
    machineSays($rig, ['type' => 'file_read_result', 'id' => $id, 'ok' => true, 'size' => 100, 'status' => 206, 'start' => 0, 'end' => 4]);

    $worker->close();

    expect(frame($rig, MessageTypes::FILE_READ_CANCEL))->toBe(['type' => 'file_read_cancel', 'id' => $id]);
});

// --- Pieces ----------------------------------------------------------------------

it('decodes a chunked body fed a byte at a time', function () {
    $wire = "4;ext=1\r\nWiki\r\n5\r\npedia\r\nE\r\n in\r\n\r\nchunks.\r\n0\r\nX-Trailer: y\r\n\r\n";
    $d = new ChunkedDecoder();
    $out = '';
    foreach (str_split($wire) as $byte) {
        $out .= $d->feed($byte);
    }

    expect($out)->toBe("Wikipedia in\r\n\r\nchunks.")->and($d->isDone())->toBeTrue();
});

it('refuses a malformed chunk size', function () {
    (new ChunkedDecoder())->feed("zz\r\n");
})->throws(UnexpectedValueException::class);

it('builds the one-time URL from the configured transfer URL when there is one', function () {
    config()->set('ai-bridge.transfers.url', 'https://studio.test/ai-bridge/transfer');

    expect(TransferHub::transferUrl('abc'))->toBe('https://studio.test/ai-bridge/transfer?transfer=abc');

    config()->set('ai-bridge.transfers.url', null);
    config()->set('ai-bridge.server.public_url', null);
    config()->set('app.url', 'https://app.test/');

    expect(TransferHub::transferUrl('abc'))->toBe('https://app.test/api/ai-bridge/ws?transfer=abc');
});

it('routes a worker upload and a machine transfer to the hub on their headers alone', function () {
    $rig = transferRig();
    $server = new BridgeWebSocketServer($rig->manager, $rig->handler, app(TokenManager::class));
    (new ReflectionProperty(BridgeWebSocketServer::class, 'transfers'))->setValue($server, $rig->hub);
    $route = new ReflectionMethod(BridgeWebSocketServer::class, 'maybeStreamTransfer');

    $relay = app(TokenManager::class)->generate('user-1', ['scope' => TokenManager::INTERNAL_RELAY_SCOPE]);
    $worker = new FakeSocket();
    $took = $route->invoke($server, $worker, "POST /api/upload HTTP/1.1\r\nAuthorization: Bearer {$relay}\r\nContent-Length: 3\r\nX-File-Name: a\r\nX-Working-Dir: %2Fw", 'ab', null);
    expect($took)->toBeTrue()->and(frame($rig, MessageTypes::UPLOAD_OFFER))->not->toBeNull();

    // A bridge token is not a relay token.
    $bad = new FakeSocket();
    $route->invoke($server, $bad, "POST /api/upload HTTP/1.1\r\nAuthorization: Bearer {$rig->bridgeToken}\r\nContent-Length: 3", '', null);
    expect($bad->response()[0])->toBe(401);

    $id = frame($rig, MessageTypes::UPLOAD_OFFER)['id'];
    $machine = new FakeSocket();
    expect($route->invoke($server, $machine, "GET /api/ai-bridge/ws?transfer={$id} HTTP/1.1\r\nAuthorization: Bearer {$rig->bridgeToken}", '', null))->toBeTrue()
        ->and($machine->response()[0])->toBe(200);

    // Everything else is left to the ordinary path.
    expect($route->invoke($server, new FakeSocket(), "GET /api/status HTTP/1.1\r\nAuthorization: Bearer x", '', null))->toBeFalse();
});

it('asks the bridge to keep handed-back files on the machine only when configured', function () {
    $rig = transferRig();
    $welcome = fn () => $rig->handler->handleMessage('conn-1', null, json_encode(['type' => 'hello', 'version' => '0.1', 'providers' => []]));

    expect($welcome()['config'])->not->toHaveKey('attachments');

    config()->set('ai-bridge.transfers.handed_back', 'device');
    expect($welcome()['config']['attachments'])->toBe('device');
});
