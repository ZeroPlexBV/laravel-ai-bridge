<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Transfers;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Models\Connection;

/**
 * Files that live on a connected machine, moved for a browser without this server
 * keeping them. The PHP-FPM half of the transfer protocol; see TransferHub for the
 * other half and for why the two meet in the serve process.
 *
 *   // Upload: stream the request body to the chat's machine.
 *   $file = app(MachineFiles::class)->upload($connection, $request->getContent(true),
 *       (int) $request->header('Content-Length'), $name, $workingDir, $mimeType);
 *   // $file->path, ->name, ->size, ->sha256, ->fileId
 *
 *   // Download: stream a file the machine recorded to the browser (Range honoured).
 *   return app(MachineFiles::class)->download($connection, $fileId, $name, $mimeType,
 *       $request->header('Range'), head: $request->isMethod('HEAD'));
 *
 * Both throw TransferRefused, whose `status` is the HTTP status to answer with and
 * whose message is a sentence a person can act on.
 *
 * `$machine` is the Connection (its `connection_key` is the user its bridge connects
 * as) or that key itself.
 */
final class MachineFiles
{
    private const CHUNK = 65536;

    public function __construct(
        private readonly TokenManager $tokens,
    ) {}

    /**
     * Stream a file to the machine, into `<workingDir>/file-uploads/`.
     *
     * Nothing is written on this server. The body is read in 64 KB pieces and written to
     * the serve process as it is read, so a slow machine slows the browser rather than
     * filling memory. Waits until the machine has confirmed the file (or refused it).
     *
     * @param  resource  $body  Readable stream positioned at the start of the file, e.g.
     *                          `$request->getContent(true)`. Under PHP-FPM, send the upload
     *                          as PUT (or any method but POST) with a raw body: PHP reads a
     *                          POST body whole before the script starts, so a POST cannot
     *                          stream. See docs/file-transfers.md for the web server side.
     * @param  int  $size  Exact byte count (the request's Content-Length). Required: the
     *                     machine refuses anything that differs.
     * @param  string  $workingDir  Absolute path of the folder the chat works in; must be
     *                              one of the machine's `workspaces` (or inside one).
     *
     * @throws TransferRefused
     */
    public function upload(Connection|string $machine, mixed $body, int $size, string $name, string $workingDir, ?string $mimeType = null): UploadedToMachine
    {
        if (! is_resource($body)) {
            throw new \InvalidArgumentException('The upload body must be a readable stream.');
        }
        if ($size < 0) {
            throw new TransferRefused(411, 'length_required', 'The upload has to say how large the file is.');
        }

        @set_time_limit(0);

        $socket = $this->connect();
        // A write blocks while the machine is slow (that is the backpressure). The serve
        // process gives up after its own stall bound and answers; this only has to
        // outlast that.
        stream_set_timeout($socket, TransferHub::PICKUP_SECONDS + TransferHub::STALL_SECONDS);
        $headers = [
            'POST /api/upload HTTP/1.1',
            'Host: ai-bridge',
            'Authorization: Bearer '.$this->relayToken($machine),
            'Content-Type: application/octet-stream',
            'Content-Length: '.$size,
            'X-File-Name: '.rawurlencode($name),
            'X-Working-Dir: '.rawurlencode($workingDir),
            'Connection: close',
        ];
        if ($mimeType !== null && $mimeType !== '') {
            $headers[] = 'X-File-Type: '.preg_replace('/[^\x20-\x7e]/', '', $mimeType);
        }

        $sent = 0;
        $earlyAnswer = false;
        if (! self::writeAll($socket, implode("\r\n", $headers)."\r\n\r\n")) {
            $earlyAnswer = true;
        }

        while (! $earlyAnswer && $sent < $size) {
            // The serve process answers early only to refuse (too large, machine gone,
            // machine said no). Looking before each write means a refusal is read
            // rather than lost to a broken pipe.
            if (self::readable($socket)) {
                $earlyAnswer = true;

                break;
            }

            $piece = fread($body, min(self::CHUNK, $size - $sent));
            if ($piece === false || $piece === '') {
                if (feof($body)) {
                    fclose($socket);

                    throw new TransferRefused(400, 'size_mismatch', "The upload ended after {$sent} of the {$size} bytes it declared.");
                }

                continue;
            }

            if (! self::writeAll($socket, $piece)) {
                $earlyAnswer = true;

                break;
            }
            $sent += strlen($piece);
        }

        // Past the last byte the machine still has to check and name the file.
        stream_set_timeout($socket, TransferHub::CONFIRM_SECONDS + TransferHub::STALL_SECONDS + 15);
        [$status, , $json] = self::readResponse($socket);
        fclose($socket);

        if ($status === 200 && is_array($json) && ($json['ok'] ?? null) === true) {
            return new UploadedToMachine(
                path: (string) $json['path'],
                name: (string) $json['name'],
                size: (int) $json['size'],
                sha256: (string) $json['sha256'],
                fileId: isset($json['file_id']) && is_string($json['file_id']) ? $json['file_id'] : null,
            );
        }

        throw TransferRefused::fromAnswer($status, $json, 'The file could not be sent to the machine.');
    }

    /**
     * Ask the machine for a file it recorded, by the `file_id` it minted.
     *
     * Returns once the machine has said whether it has the file: status (200, 206 or 416),
     * size and range are known, and the bytes are read from the returned stream.
     *
     * @throws TransferRefused
     */
    public function open(Connection|string $machine, string $fileId, ?string $range = null, bool $head = false): MachineFileStream
    {
        @set_time_limit(0);

        $socket = $this->connect();
        $body = (string) json_encode(array_filter([
            'file_id' => $fileId,
            'range' => $range !== null && $range !== '' ? $range : null,
            'head' => $head ? true : null,
        ], static fn ($v) => $v !== null));

        self::writeAll($socket, implode("\r\n", [
            'POST /api/file-read HTTP/1.1',
            'Host: ai-bridge',
            'Authorization: Bearer '.$this->relayToken($machine),
            'Content-Type: application/json',
            'Content-Length: '.strlen($body),
            'Connection: close',
        ])."\r\n\r\n".$body);

        stream_set_timeout($socket, TransferHub::ANSWER_SECONDS + 10);
        [$status, $headers, $json] = self::readResponse($socket, bodyIfJsonOnly: true);

        $result = isset($headers['x-transfer-result']) ? json_decode($headers['x-transfer-result'], true) : null;
        if ($status !== 200 || ! is_array($result) || ($result['ok'] ?? null) !== true) {
            fclose($socket);

            throw TransferRefused::fromAnswer($status, $json, 'The machine would not hand the file over.');
        }

        // From here the bytes: the stall bound is the serve process's, plus a margin.
        stream_set_timeout($socket, TransferHub::PICKUP_SECONDS + TransferHub::STALL_SECONDS + 15);

        return new MachineFileStream(
            socket: $socket,
            status: (int) $result['status'],
            size: (int) $result['size'],
            start: (int) $result['start'],
            end: (int) $result['end'],
            length: (int) $result['length'],
        );
    }

    /**
     * A browser response streaming a machine-held file: status, Content-Length,
     * Content-Range and Accept-Ranges from the machine's answer, the bytes as they come.
     *
     * The content is whatever the machine says it is, so it is served defensively:
     * `nosniff`, `Content-Security-Policy: sandbox`, `private, no-store`, and
     * `X-Accel-Buffering: no` so nginx does not spool it to disk. Use disposition
     * `inline` only for types you render safely.
     *
     * @throws TransferRefused Before any byte is sent, when the machine will not serve it.
     */
    public function download(
        Connection|string $machine,
        string $fileId,
        string $name,
        ?string $mimeType = null,
        ?string $range = null,
        bool $head = false,
        string $disposition = 'attachment',
    ): Response {
        $file = $this->open($machine, $fileId, $range, $head);

        $headers = [
            'Content-Type' => $mimeType !== null && $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $disposition === 'inline' ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                self::asciiFallback($name),
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'private, no-store',
            'Accept-Ranges' => 'bytes',
        ];

        if ($file->status === 416) {
            $file->close();

            return new Response('', 416, $headers + ['Content-Range' => "bytes */{$file->size}"]);
        }

        $headers['Content-Length'] = (string) $file->length;
        if ($file->status === 206) {
            $headers['Content-Range'] = "bytes {$file->start}-{$file->end}/{$file->size}";
        }

        if ($head || $file->length === 0) {
            $file->close();

            return new Response('', $file->status, $headers);
        }

        return new StreamedResponse(function () use ($file): void {
            $file->pipe(static function (string $bytes): bool {
                echo $bytes;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                return connection_aborted() === 0;
            });
        }, $file->status, $headers);
    }

    /** @return resource */
    private function connect(): mixed
    {
        [$host, $port] = self::internalEndpoint();
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, (float) config('ai-bridge.server.relay_timeout', 5));

        if ($socket === false) {
            throw new TransferRefused(502, 'unreachable', 'The connection to machines is not running on this server right now. Try again in a moment.');
        }

        stream_set_blocking($socket, true);

        return $socket;
    }

    private function relayToken(Connection|string $machine): string
    {
        $userId = $machine instanceof Connection ? (string) $machine->connection_key : $machine;
        if ($userId === '') {
            throw new TransferRefused(409, 'not_connected', 'This connection has no machine.');
        }

        return $this->tokens->generate($userId, ['scope' => TokenManager::INTERNAL_RELAY_SCOPE], 300);
    }

    /**
     * Where the serve process listens, as the other internal calls resolve it.
     *
     * @return array{0: string, 1: int}
     */
    private static function internalEndpoint(): array
    {
        $relayUrl = config('ai-bridge.server.relay_url');
        if (is_string($relayUrl) && $relayUrl !== '') {
            $parts = parse_url($relayUrl);
            if (($parts['scheme'] ?? 'http') !== 'http') {
                // A TLS relay would need a TLS client here; the serve process is expected
                // on the same host or network for file transfers.
                throw new TransferRefused(500, 'unsupported', 'File transfers need the serve process on a plain internal http:// address.');
            }

            return [(string) ($parts['host'] ?? '127.0.0.1'), (int) ($parts['port'] ?? 80)];
        }

        $host = (string) config('ai-bridge.server.host', '127.0.0.1');

        return [$host === '0.0.0.0' ? '127.0.0.1' : $host, (int) config('ai-bridge.server.port', 8085)];
    }

    /** @param resource $socket */
    private static function writeAll(mixed $socket, string $data): bool
    {
        // A refusal closes the far end mid-upload; the resulting EPIPE is expected and is
        // answered by reading the refusal, not reported as a PHP warning.
        set_error_handler(static fn (): bool => true);

        try {
            while ($data !== '') {
                $n = fwrite($socket, $data);
                if ($n === false || $n === 0) {
                    return false;
                }
                $data = (string) substr($data, $n);
            }

            return true;
        } finally {
            restore_error_handler();
        }
    }

    /** @param resource $socket */
    private static function readable(mixed $socket): bool
    {
        $r = [$socket];
        $w = $e = null;

        return @stream_select($r, $w, $e, 0) > 0;
    }

    /**
     * Read the status line and headers, and the body when it is a JSON answer.
     *
     * @param  resource  $socket
     * @return array{0: int, 1: array<string, string>, 2: array<string, mixed>|null}
     */
    private static function readResponse(mixed $socket, bool $bodyIfJsonOnly = false): array
    {
        $status = 0;
        $headers = [];

        $line = fgets($socket);
        if ($line === false) {
            $meta = stream_get_meta_data($socket);
            throw new TransferRefused(504, $meta['timed_out'] ? 'timeout' : 'unreachable',
                $meta['timed_out'] ? 'The machine took too long to answer.' : 'The connection to the machine broke off.');
        }
        if (preg_match('#^HTTP/1\.[01] (\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }

        while (($line = fgets($socket)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                break;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }

        $isJson = str_starts_with($headers['content-type'] ?? '', 'application/json');
        if ($bodyIfJsonOnly && ! $isJson) {
            return [$status, $headers, null];
        }

        $length = isset($headers['content-length']) ? (int) $headers['content-length'] : null;
        $body = '';
        while (! feof($socket) && ($length === null || strlen($body) < $length)) {
            $piece = fread($socket, $length === null ? 8192 : max(1, $length - strlen($body)));
            if ($piece === false || $piece === '') {
                if (stream_get_meta_data($socket)['timed_out']) {
                    break;
                }
                if (feof($socket)) {
                    break;
                }
            } else {
                $body .= $piece;
            }
        }

        $json = json_decode($body, true);

        return [$status, $headers, is_array($json) ? $json : null];
    }

    private static function asciiFallback(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7e]|["\\\\%\/]/', '_', $name);

        return is_string($ascii) && $ascii !== '' ? $ascii : 'file';
    }
}
