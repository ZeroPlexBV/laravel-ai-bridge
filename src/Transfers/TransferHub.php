<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Transfers;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Support\BridgeLog;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/**
 * Where a file passing between a browser and a connected machine meets the machine,
 * inside the serve process (`ai-bridge:serve`).
 *
 * Bridge 0.18+ moves files without ever putting their bytes on the WebSocket (its
 * frames are capped far below a file) and without the server keeping a copy:
 *
 *  - UPLOAD. A PHP-FPM worker holding the browser's request streams its body here
 *    (`POST /api/upload`, internal, relay token). This process tells the machine
 *    `upload_offer` with a one-time URL; the machine GETs that URL, and the worker's
 *    body is piped into that response as it arrives, counted and hashed on the way,
 *    with backpressure both ways (a slow machine pauses the worker's socket, which
 *    blocks the worker's write, which stops it reading the browser). After the last
 *    byte the machine is told `upload_sent` (size + sha256), checks them, names the
 *    file in `<working_dir>/file-uploads/`, and answers `upload_done` with the path
 *    and the `file_id` it recorded it under. That answer is the worker's response.
 *
 *  - DOWNLOAD. A worker asks here for a file by the machine's own `file_id`
 *    (`POST /api/file-read`, with the browser's Range). This process sends
 *    `file_read`; the machine answers `file_read_result` (size and the range it will
 *    send, or why not), which goes back to the worker at once as response headers so
 *    it can answer the browser; then the machine POSTs the bytes to the one-time URL
 *    and they are piped to the worker as they arrive.
 *
 * Why meet HERE rather than in PHP-FPM: the rendezvous has to be one process that can
 * hold two sockets at once and move bytes between them without holding the file. A
 * pair of FPM workers cannot (they share nothing but Redis, and the machine's POST
 * would be read whole by PHP before the script even starts). This process already
 * holds the machine's socket, is non-blocking, and answers plain HTTP on the same
 * port as the WebSocket, which the public WebSocket location already reaches: the
 * one-time URL is that same location with `?transfer=<id>`, so no new route has to
 * be opened to the internet.
 *
 * Single process, like the connection registry: the worker's request and the
 * machine's request must reach the same serve process, which they do because there
 * is one.
 *
 * Every entry point is guarded: this runs inside the event loop, where an uncaught
 * throwable exits the process and drops every connected machine.
 */
final class TransferHub
{
    /** How long the machine has to come for the bytes (or start sending them) after being told. */
    public const PICKUP_SECONDS = 30;

    /** How long nothing may move, either way, before a transfer is given up. */
    public const STALL_SECONDS = 60;

    /** How long the machine has to confirm an upload after the last byte passed. */
    public const CONFIRM_SECONDS = 60;

    /** How long the machine has to say whether it has a file. */
    public const ANSWER_SECONDS = 30;

    /** @var array<string, array<string, mixed>> Uploads in flight, by transfer id. */
    private array $uploads = [];

    /** @var array<string, array<string, mixed>> Downloads in flight, by transfer id. */
    private array $downloads = [];

    public function __construct(
        private readonly BridgeConnectionManager $connections,
        private readonly TokenManager $tokens,
        private readonly LoopInterface $loop,
    ) {}

    /**
     * The one-time URL the machine is sent for a transfer.
     *
     * Must be on the origin the machine connected to (the bridge refuses any other, and
     * any non-HTTPS one off loopback). Defaults to the public WebSocket URL
     * (`ai-bridge.server.public_url`) with its scheme made http(s), else APP_URL +
     * `/api/ai-bridge/ws`: the location that already routes to this process. Override
     * with `ai-bridge.transfers.url` when a dedicated location exists.
     */
    public static function transferUrl(string $id): string
    {
        $base = config('ai-bridge.transfers.url');

        if (! is_string($base) || $base === '') {
            $public = config('ai-bridge.server.public_url');
            $base = is_string($public) && $public !== ''
                ? (string) preg_replace('#^ws(s?)://#i', 'http$1://', $public)
                : rtrim((string) config('app.url'), '/').'/api/ai-bridge/ws';
        }

        return $base.(str_contains($base, '?') ? '&' : '?').'transfer='.rawurlencode($id);
    }

    public function inFlight(): int
    {
        return count($this->uploads) + count($this->downloads);
    }

    // =====================================================================
    // Upload
    // =====================================================================

    /**
     * A worker has started streaming a browser's file here.
     *
     * Called by the server as soon as the request's headers are in, before the body:
     * whatever of the body already arrived is in $early, the rest comes as 'data'.
     *
     * @param  array<string, string>  $headers  Lower-cased header names.
     */
    public function startUpload(ConnectionInterface $worker, string $userId, array $headers, string $early): void
    {
        try {
            $this->beginUpload($worker, $userId, $headers, $early);
        } catch (\Throwable $e) {
            BridgeLog::warning('upload could not start', ['error' => $e->getMessage()]);
            self::answer($worker, 500, ['ok' => false, 'code' => 'upload_failed', 'error' => 'The upload could not be started.'], drain: true);
        }
    }

    /** @param  array<string, string>  $headers */
    private function beginUpload(ConnectionInterface $worker, string $userId, array $headers, string $early): void
    {
        $size = $headers['content-length'] ?? null;
        $name = rawurldecode($headers['x-file-name'] ?? '');
        $workingDir = rawurldecode($headers['x-working-dir'] ?? '');
        $mime = trim(explode(';', $headers['x-file-type'] ?? '', 2)[0]);

        if ($size === null || ! ctype_digit($size)) {
            self::answer($worker, 411, ['ok' => false, 'code' => 'length_required', 'error' => 'The upload has to say how large the file is.'], drain: true);

            return;
        }
        $size = (int) $size;

        if ($name === '' || $workingDir === '') {
            self::answer($worker, 400, ['ok' => false, 'code' => 'invalid_request', 'error' => 'An upload needs a file name and the working folder to put it in.'], drain: true);

            return;
        }

        if (! $this->connections->hasConnection($userId)) {
            self::answer($worker, 409, ['ok' => false, 'code' => 'not_connected', 'error' => 'The machine is not connected. Files go straight to it rather than being kept here, so it has to be on.'], drain: true);

            return;
        }

        if (! $this->connections->bridgeSupports($userId, 'file_uploads')) {
            self::answer($worker, 409, ['ok' => false, 'code' => 'unsupported', 'error' => 'The bridge on this machine is too old to receive files directly. Update it there, then add the file again.'], drain: true);

            return;
        }

        $max = $this->connections->getBridgeInfo($userId)['attachment_limits']['max_file_bytes'] ?? null;
        if (is_int($max) && $size > $max) {
            self::answer($worker, 413, ['ok' => false, 'code' => 'upload_too_large', 'error' => sprintf(
                'That file is %s, and this machine accepts at most %s per file (its --attachment-max-mb setting).',
                self::human($size), self::human($max),
            ), 'max_file_bytes' => $max], drain: true);

            return;
        }

        $id = bin2hex(random_bytes(16));
        $worker->pause();

        $this->uploads[$id] = [
            'user_id' => $userId,
            'worker' => $worker,
            'bridge' => null,
            'size' => $size,
            'name' => $name,
            'passed' => 0,
            'hash' => hash_init('sha256'),
            'sha256' => null,
            'held' => $early,
            'phase' => 'offered',   // offered → piping → sent → (answered)
            'timer' => null,
        ];

        $worker->on('data', function (string $data) use ($id): void {
            $this->guard(fn () => $this->uploadData($id, $data));
        });
        $worker->on('close', function () use ($id): void {
            $this->guard(fn () => $this->uploadWorkerGone($id));
        });

        $this->arm($id, 'upload', self::PICKUP_SECONDS, 504, 'pickup_timeout',
            'The machine did not come to collect the file within '.self::PICKUP_SECONDS.'s. Check that its bridge can reach this server over HTTPS, then add the file again.');

        $sent = $this->connections->sendToUser($userId, array_filter([
            'type' => MessageTypes::UPLOAD_OFFER,
            'id' => $id,
            'url' => self::transferUrl($id),
            'working_dir' => $workingDir,
            'name' => $name,
            'mime_type' => $mime !== '' ? $mime : null,
            'size' => $size,
        ], static fn ($v) => $v !== null));

        if (! $sent) {
            $this->failUpload($id, 502, 'not_connected', 'The machine could not be told about the file.', false);
        }
    }

    /** Bytes from the worker. Held until the machine has come for them. */
    private function uploadData(string $id, string $data): void
    {
        $u = $this->uploads[$id] ?? null;
        if ($u === null || $data === '') {
            return;
        }

        if ($u['bridge'] === null) {
            $this->uploads[$id]['held'] .= $data;
            $u['worker']->pause();

            return;
        }

        $this->forwardUpload($id, $data);
    }

    private function forwardUpload(string $id, string $data): void
    {
        $u = $this->uploads[$id];
        $passed = $u['passed'] + strlen($data);

        if ($passed > $u['size']) {
            $this->failUpload($id, 400, 'size_mismatch', "The upload sent more than the {$u['size']} bytes it declared.");

            return;
        }

        hash_update($u['hash'], $data);
        $this->uploads[$id]['passed'] = $passed;

        /** @var ConnectionInterface $bridge */
        $bridge = $u['bridge'];
        if (! $bridge->write($data)) {
            // The machine is slower than the browser: stop reading the worker until it
            // has caught up. The worker's own write then blocks, and so does its read
            // of the browser, which is the backpressure reaching the person's upload.
            $u['worker']->pause();
            $bridge->once('drain', function () use ($id): void {
                $this->guard(function () use ($id): void {
                    if (($this->uploads[$id]['phase'] ?? null) === 'piping') {
                        $this->uploads[$id]['worker']->resume();
                    }
                });
            });
        }

        $this->arm($id, 'upload', self::STALL_SECONDS, 504, 'stalled',
            'Nothing moved for '.self::STALL_SECONDS.'s between the browser and the machine. Add the file again.');

        if ($passed === $u['size']) {
            $this->uploadAllPassed($id);
        }
    }

    private function uploadAllPassed(string $id): void
    {
        $u = $this->uploads[$id];
        $sha256 = hash_final($u['hash']);
        $this->uploads[$id]['sha256'] = $sha256;
        $this->uploads[$id]['phase'] = 'sent';

        $u['bridge']->end();

        $this->connections->sendToUser($u['user_id'], [
            'type' => MessageTypes::UPLOAD_SENT,
            'id' => $id,
            'size' => $u['passed'],
            'sha256' => $sha256,
        ]);

        $this->arm($id, 'upload', self::CONFIRM_SECONDS, 504, 'not_confirmed',
            'The machine received the file but never confirmed it was saved.');
    }

    /** The machine came for the bytes. */
    private function uploadCollected(string $id, ConnectionInterface $bridge): void
    {
        $u = $this->uploads[$id];
        $this->uploads[$id]['bridge'] = $bridge;
        $this->uploads[$id]['phase'] = 'piping';

        $bridge->write(
            "HTTP/1.1 200 OK\r\n"
            ."Content-Type: application/octet-stream\r\n"
            ."Content-Length: {$u['size']}\r\n"
            ."Cache-Control: no-store\r\n"
            ."X-Accel-Buffering: no\r\n"
            ."Connection: close\r\n\r\n"
        );

        $bridge->on('close', function () use ($id): void {
            $this->guard(function () use ($id): void {
                if (($this->uploads[$id]['phase'] ?? null) === 'piping') {
                    $this->failUpload($id, 502, 'machine_stopped', 'The machine stopped reading the file part way through.');
                }
            });
        });

        $this->arm($id, 'upload', self::STALL_SECONDS, 504, 'stalled',
            'Nothing moved for '.self::STALL_SECONDS.'s between the browser and the machine. Add the file again.');

        $held = $u['held'];
        $this->uploads[$id]['held'] = '';

        if ($u['size'] === 0) {
            $this->uploadAllPassed($id);

            return;
        }

        if ($held !== '') {
            $this->forwardUpload($id, $held);
        }

        if (($this->uploads[$id]['phase'] ?? null) === 'piping') {
            $u['worker']->resume();
        }
    }

    /** The worker's socket closed: the browser went away, or the worker died. */
    private function uploadWorkerGone(string $id): void
    {
        $u = $this->uploads[$id] ?? null;
        if ($u === null || $u['phase'] === 'sent') {
            // After the last byte the worker is only waiting for the answer; if it gave
            // up, the machine still finishes and records the file. Nothing to stop.
            if ($u !== null) {
                $this->forgetUpload($id);
            }

            return;
        }

        $this->failUpload($id, 499, 'client_gone', 'The upload was stopped.', true, false);
    }

    /** `upload_done` from the machine. */
    public function onUploadDone(string $userId, array $message): void
    {
        $this->guard(function () use ($userId, $message): void {
            $id = $message['id'] ?? null;
            $u = is_string($id) ? ($this->uploads[$id] ?? null) : null;

            if ($u === null || $u['user_id'] !== $userId) {
                return;
            }

            if (($message['ok'] ?? null) !== true) {
                $code = is_string($message['code'] ?? null) && $message['code'] !== '' ? $message['code'] : 'upload_failed';
                $why = is_string($message['error'] ?? null) && $message['error'] !== '' ? $message['error'] : 'it did not say why';
                // The machine has already cleaned up after its own refusal: no abort.
                $this->failUpload($id, $code === 'upload_too_large' ? 413 : 422, $code, 'The machine did not take the file: '.$why, false);

                return;
            }

            if ($u['phase'] !== 'sent') {
                $this->failUpload($id, 502, 'upload_failed', 'The machine said it had the file before it was all sent.');

                return;
            }

            $path = $message['path'] ?? null;
            // Its own count and digest have to be the ones that passed through here.
            if (! is_string($path) || $path === ''
                || ($message['size'] ?? null) !== $u['passed']
                || ! is_string($message['sha256'] ?? null) || strtolower($message['sha256']) !== $u['sha256']) {
                $this->failUpload($id, 502, 'upload_mismatch', 'The machine reported a different file from the one that was sent.');

                return;
            }

            $name = is_string($message['name'] ?? null) && $message['name'] !== '' ? $message['name'] : $u['name'];
            $fileId = is_string($message['file_id'] ?? null) && $message['file_id'] !== '' ? $message['file_id'] : null;

            self::answer($u['worker'], 200, [
                'ok' => true,
                'path' => $path,
                'name' => $name,
                'size' => $u['passed'],
                'sha256' => $u['sha256'],
                'file_id' => $fileId,
            ]);
            $this->forgetUpload($id);
        });
    }

    private function failUpload(string $id, int $status, string $code, string $error, bool $tellMachine = true, bool $answerWorker = true): void
    {
        $u = $this->uploads[$id] ?? null;
        if ($u === null) {
            return;
        }
        $this->forgetUpload($id);

        BridgeLog::info('upload not completed', ['id' => $id, 'code' => $code, 'passed' => $u['passed'], 'size' => $u['size']]);

        if ($tellMachine) {
            // So the partial file goes now rather than when the machine's own clock notices.
            $this->connections->sendToUser($u['user_id'], ['type' => MessageTypes::UPLOAD_ABORT, 'id' => $id, 'reason' => $error]);
        }

        if ($u['bridge'] instanceof ConnectionInterface) {
            $u['bridge']->close();
        }

        if ($answerWorker) {
            self::answer($u['worker'], $status, ['ok' => false, 'code' => $code, 'error' => $error], drain: true);
        }
    }

    private function forgetUpload(string $id): void
    {
        $timer = $this->uploads[$id]['timer'] ?? null;
        if ($timer instanceof TimerInterface) {
            $this->loop->cancelTimer($timer);
        }
        unset($this->uploads[$id]);
    }

    // =====================================================================
    // Download
    // =====================================================================

    /**
     * A worker asks for a file the machine recorded, to pipe to a browser.
     *
     * @param  array<string, mixed>  $body  `{file_id, range?, head?}`
     */
    public function startDownload(ConnectionInterface $worker, string $userId, array $body): void
    {
        try {
            $this->beginDownload($worker, $userId, $body);
        } catch (\Throwable $e) {
            BridgeLog::warning('download could not start', ['error' => $e->getMessage()]);
            self::answer($worker, 500, ['ok' => false, 'code' => 'file_failed', 'error' => 'The download could not be started.']);
        }
    }

    /** @param  array<string, mixed>  $body */
    private function beginDownload(ConnectionInterface $worker, string $userId, array $body): void
    {
        $fileId = $body['file_id'] ?? null;
        $range = $body['range'] ?? null;
        $head = ($body['head'] ?? false) === true;

        if (! is_string($fileId) || $fileId === '' || ($range !== null && ! is_string($range))) {
            self::answer($worker, 400, ['ok' => false, 'code' => 'invalid_request', 'error' => 'A download needs the file_id the machine recorded the file under.']);

            return;
        }

        if (! $this->connections->hasConnection($userId)) {
            self::answer($worker, 409, ['ok' => false, 'code' => 'not_connected', 'error' => 'This file is only on the machine, and the machine is not connected.']);

            return;
        }

        if (! $this->connections->bridgeSupports($userId, 'file_downloads')) {
            self::answer($worker, 409, ['ok' => false, 'code' => 'unsupported', 'error' => 'The bridge on this machine is too old to hand files back. Update it there, then try again.']);

            return;
        }

        $id = bin2hex(random_bytes(16));
        $this->downloads[$id] = [
            'user_id' => $userId,
            'worker' => $worker,
            'bridge' => null,
            'phase' => 'asked',   // asked → answered → piping → done
            'head' => $head,
            'expected' => 0,
            'passed' => 0,
            'decoder' => null,
            'left' => null,
            'timer' => null,
        ];

        $worker->on('close', function () use ($id): void {
            $this->guard(fn () => $this->downloadWorkerGone($id));
        });

        $this->arm($id, 'download', self::ANSWER_SECONDS, 504, 'no_answer',
            'The machine did not answer about the file within '.self::ANSWER_SECONDS.'s.');

        $sent = $this->connections->sendToUser($userId, array_filter([
            'type' => MessageTypes::FILE_READ,
            'id' => $id,
            'file_id' => $fileId,
            'url' => self::transferUrl($id),
            'range' => is_string($range) && $range !== '' ? $range : null,
            'head' => $head ? true : null,
        ], static fn ($v) => $v !== null));

        if (! $sent) {
            $this->failDownload($id, 502, 'not_connected', 'The machine could not be asked for the file.', false);
        }
    }

    /** `file_read_result` from the machine. */
    public function onFileReadResult(string $userId, array $message): void
    {
        $this->guard(function () use ($userId, $message): void {
            $id = $message['id'] ?? null;
            $d = is_string($id) ? ($this->downloads[$id] ?? null) : null;

            if ($d === null || $d['user_id'] !== $userId || $d['phase'] !== 'asked') {
                return;
            }

            if (($message['ok'] ?? null) !== true) {
                $code = is_string($message['code'] ?? null) && $message['code'] !== '' ? $message['code'] : 'file_failed';
                $why = is_string($message['error'] ?? null) && $message['error'] !== '' ? $message['error'] : 'it would not hand the file over';
                $status = in_array($code, ['file_gone', 'file_unknown'], true) ? 410 : ($code === 'file_changed' ? 409 : 502);
                $this->failDownload($id, $status, $code, $why, false);

                return;
            }

            $size = $message['size'] ?? null;
            $httpStatus = $message['status'] ?? null;
            $start = $message['start'] ?? null;
            $end = $message['end'] ?? null;

            if (! is_int($size) || $size < 0 || ! in_array($httpStatus, [200, 206, 416], true) || ! is_int($start) || ! is_int($end)) {
                $this->failDownload($id, 502, 'file_failed', 'The machine answered about the file in a way this server does not understand.');

                return;
            }

            $length = $httpStatus === 416 ? 0 : max(0, $end - $start + 1);
            $result = ['ok' => true, 'size' => $size, 'status' => $httpStatus, 'start' => $start, 'end' => $end, 'length' => $length];

            /** @var ConnectionInterface $worker */
            $worker = $d['worker'];
            $worker->write(
                "HTTP/1.1 200 OK\r\n"
                ."Content-Type: application/octet-stream\r\n"
                .'X-Transfer-Result: '.json_encode($result)."\r\n"
                ."Connection: close\r\n\r\n"
            );

            // The machine sends no bytes for a HEAD, a 416, or an empty range.
            if ($length === 0 || $d['head']) {
                $worker->end();
                $this->forgetDownload($id);

                return;
            }

            $this->downloads[$id]['phase'] = 'answered';
            $this->downloads[$id]['expected'] = $length;

            $this->arm($id, 'download', self::PICKUP_SECONDS, 504, 'never_started',
                'The machine said it would send the file and never started.');
        });
    }

    /**
     * The machine POSTing the bytes. Headers are in; $early is whatever of the body came
     * with them.
     *
     * @param  array<string, string>  $headers
     */
    private function downloadArrived(string $id, ConnectionInterface $bridge, array $headers, string $early): void
    {
        $d = $this->downloads[$id];
        $this->downloads[$id]['bridge'] = $bridge;
        $this->downloads[$id]['phase'] = 'piping';

        $chunked = str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked');
        $this->downloads[$id]['decoder'] = $chunked ? new ChunkedDecoder() : null;
        $this->downloads[$id]['left'] = $chunked ? null : (isset($headers['content-length']) && ctype_digit($headers['content-length']) ? (int) $headers['content-length'] : null);

        $bridge->on('data', function (string $data) use ($id): void {
            $this->guard(fn () => $this->downloadData($id, $data));
        });
        $bridge->on('close', function () use ($id): void {
            $this->guard(function () use ($id): void {
                if (($this->downloads[$id]['phase'] ?? null) === 'piping') {
                    $this->failDownload($id, 502, 'machine_stopped', 'The machine stopped sending the file.', false);
                }
            });
        });

        $this->arm($id, 'download', self::STALL_SECONDS, 504, 'stalled', 'The machine stopped sending the file.');

        if ($early !== '') {
            $this->downloadData($id, $early);
        } elseif ($this->downloads[$id]['left'] === 0) {
            $this->downloadData($id, '');
        }
    }

    private function downloadData(string $id, string $data): void
    {
        $d = $this->downloads[$id] ?? null;
        if ($d === null || $d['phase'] !== 'piping') {
            return;
        }

        $finished = false;
        if ($d['decoder'] instanceof ChunkedDecoder) {
            try {
                $payload = $d['decoder']->feed($data);
            } catch (\UnexpectedValueException $e) {
                $this->failDownload($id, 502, 'file_failed', 'The machine sent a malformed body: '.$e->getMessage());

                return;
            }
            $finished = $d['decoder']->isDone();
        } else {
            $payload = $data;
            if ($d['left'] !== null) {
                $payload = substr($data, 0, $d['left']);
                $this->downloads[$id]['left'] = $d['left'] - strlen($payload);
                $finished = $this->downloads[$id]['left'] === 0;
            }
        }

        $passed = $d['passed'] + strlen($payload);
        if ($passed > $d['expected']) {
            $this->failDownload($id, 502, 'file_failed', 'The machine sent more than the range it announced.');

            return;
        }
        $this->downloads[$id]['passed'] = $passed;

        /** @var ConnectionInterface $worker */
        $worker = $d['worker'];
        /** @var ConnectionInterface $bridge */
        $bridge = $d['bridge'];

        if ($payload !== '' && ! $worker->write($payload)) {
            // The browser is slower than the machine: stop reading the machine.
            $bridge->pause();
            $worker->once('drain', function () use ($id): void {
                $this->guard(function () use ($id): void {
                    if (($this->downloads[$id]['phase'] ?? null) === 'piping') {
                        $this->downloads[$id]['bridge']->resume();
                    }
                });
            });
        }

        $this->arm($id, 'download', self::STALL_SECONDS, 504, 'stalled', 'The machine stopped sending the file.');

        if ($finished || $passed === $d['expected'] && $d['decoder'] === null && $d['left'] === null) {
            $this->downloads[$id]['phase'] = 'done';
            $worker->end();
            self::answer($bridge, 200, ['ok' => true]);
            $this->forgetDownload($id);
        }
    }

    /** The worker's socket closed: the browser went away. */
    private function downloadWorkerGone(string $id): void
    {
        $d = $this->downloads[$id] ?? null;
        if ($d === null || $d['phase'] === 'done') {
            return;
        }

        $this->failDownload($id, 499, 'client_gone', 'the download was stopped', true, false);
    }

    private function failDownload(string $id, int $status, string $code, string $error, bool $tellMachine = true, bool $answerWorker = true): void
    {
        $d = $this->downloads[$id] ?? null;
        if ($d === null) {
            return;
        }
        $this->forgetDownload($id);

        BridgeLog::info('download not completed', ['id' => $id, 'code' => $code, 'passed' => $d['passed']]);

        if ($tellMachine) {
            $this->connections->sendToUser($d['user_id'], ['type' => MessageTypes::FILE_READ_CANCEL, 'id' => $id]);
        }

        if ($d['bridge'] instanceof ConnectionInterface) {
            self::answer($d['bridge'], $status === 499 ? 409 : 502, ['ok' => false, 'error' => $error]);
        }

        if (! $answerWorker) {
            return;
        }

        if ($d['phase'] === 'asked') {
            self::answer($d['worker'], $status, ['ok' => false, 'code' => $code, 'error' => $error]);
        } else {
            // Headers already went out: the worker sees the body end short of the
            // announced length, which it reports as a broken transfer.
            $d['worker']->close();
        }
    }

    private function forgetDownload(string $id): void
    {
        $timer = $this->downloads[$id]['timer'] ?? null;
        if ($timer instanceof TimerInterface) {
            $this->loop->cancelTimer($timer);
        }
        unset($this->downloads[$id]);
    }

    // =====================================================================
    // The machine's side, and endings
    // =====================================================================

    /**
     * An HTTP request from a machine for `?transfer=<id>`: GET to collect an upload,
     * POST to deliver a download. Headers are in; $early is any body that came with them.
     *
     * Authorised by the machine's own connection token, whose subject must be the user
     * the transfer was offered to, and single-use: the id was sent over that user's
     * socket and nowhere else.
     *
     * @param  array<string, string>  $headers  Lower-cased names.
     */
    public function machineRequest(ConnectionInterface $conn, string $method, string $id, array $headers, string $early): void
    {
        try {
            $auth = $headers['authorization'] ?? '';
            $userId = null;
            if (str_starts_with($auth, 'Bearer ')) {
                try {
                    $userId = (string) $this->tokens->validate(substr($auth, 7))->sub;
                } catch (\Throwable) {
                    $userId = null;
                }
            }

            if ($userId === null) {
                self::answer($conn, 401, ['error' => 'token_invalid']);

                return;
            }

            $u = $this->uploads[$id] ?? null;
            if ($method === 'GET' && $u !== null && $u['user_id'] === $userId && $u['phase'] === 'offered') {
                $this->uploadCollected($id, $conn);

                return;
            }

            $d = $this->downloads[$id] ?? null;
            if ($method === 'POST' && $d !== null && $d['user_id'] === $userId && $d['phase'] === 'answered') {
                $this->downloadArrived($id, $conn, $headers, $early);

                return;
            }

            self::answer($conn, 404, ['error' => 'no_such_transfer', 'message' => 'No transfer is waiting under that id.']);
        } catch (\Throwable $e) {
            BridgeLog::warning('machine transfer request failed', ['id' => $id, 'error' => $e->getMessage()]);
            try {
                $conn->close();
            } catch (\Throwable) {
            }
        }
    }

    /** A user's machine went away: every transfer of theirs ends now rather than on a timer. */
    public function userGone(string $userId): void
    {
        $this->guard(function () use ($userId): void {
            foreach ($this->uploads as $id => $u) {
                if ($u['user_id'] === $userId) {
                    $this->failUpload((string) $id, 502, 'machine_gone', 'The machine went away while the file was being sent. Add it again once it is back.', false);
                }
            }
            foreach ($this->downloads as $id => $d) {
                if ($d['user_id'] === $userId) {
                    $this->failDownload((string) $id, 502, 'machine_gone', 'The machine went away before the file was sent.', false);
                }
            }
        });
    }

    /** (Re)arm the one timer a transfer has; when it fires, the transfer fails. */
    private function arm(string $id, string $kind, int $seconds, int $status, string $code, string $error): void
    {
        $table = $kind === 'upload' ? 'uploads' : 'downloads';
        $old = $this->{$table}[$id]['timer'] ?? null;
        if ($old instanceof TimerInterface) {
            $this->loop->cancelTimer($old);
        }

        $this->{$table}[$id]['timer'] = $this->loop->addTimer($seconds, function () use ($id, $kind, $status, $code, $error): void {
            $this->guard(function () use ($id, $kind, $status, $code, $error): void {
                if ($kind === 'upload') {
                    $this->uploads[$id]['timer'] = null;
                    $this->failUpload($id, $status, $code, $error);
                } else {
                    $this->downloads[$id]['timer'] = null;
                    $this->failDownload($id, $status, $code, $error);
                }
            });
        });
    }

    /** Run a callback from the event loop without letting anything escape it. */
    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            BridgeLog::warning('file transfer callback failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Write a JSON response and close.
     *
     * $drain: the peer may still be sending (a worker mid-upload). Closing a socket with
     * unread input makes the kernel reset it, which can destroy the response before the
     * peer reads it. So the rest is read and discarded until the peer closes, bounded by
     * a short timer.
     *
     * @param  array<string, mixed>  $data
     */
    public static function answer(ConnectionInterface $conn, int $status, array $data, bool $drain = false): void
    {
        $texts = [200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found', 409 => 'Conflict',
            410 => 'Gone', 411 => 'Length Required', 413 => 'Payload Too Large', 422 => 'Unprocessable Content',
            499 => 'Client Closed Request', 500 => 'Internal Server Error', 502 => 'Bad Gateway', 504 => 'Gateway Timeout'];
        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        try {
            $conn->write("HTTP/1.1 {$status} ".($texts[$status] ?? 'Error')."\r\n"
                ."Content-Type: application/json\r\n"
                .'Content-Length: '.strlen($json)."\r\n"
                ."Connection: close\r\n\r\n".$json);

            if ($drain) {
                // Not end(): that closes the socket once the write is flushed, with the
                // peer's bytes still arriving. The peer stops writing when it sees the
                // answer, reads it by its Content-Length, and closes; a timer covers one
                // that does not.
                $conn->removeAllListeners('data');
                $conn->on('data', static function (): void {});
                $conn->resume();
                $timer = \React\EventLoop\Loop::addTimer(10, static fn () => $conn->close());
                $conn->on('close', static fn () => \React\EventLoop\Loop::cancelTimer($timer));

                return;
            }

            $conn->end();
        } catch (\Throwable) {
        }
    }

    private static function human(int $bytes): string
    {
        foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $unit => $size) {
            if ($bytes >= $size) {
                return round($bytes / $size, 1).' '.$unit;
            }
        }

        return $bytes.' B';
    }
}
