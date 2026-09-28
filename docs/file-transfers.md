# Files on the machine: streamed uploads and downloads

Bridge **0.18+** can receive a file a person picks in a chat, and hand back a file it
holds, without the server keeping a copy and without the bytes riding the WebSocket.
This package implements the server side. An application calls two methods from an
ordinary controller; everything else happens in the serve process.

```php
use Tetrix\AiBridge\Transfers\MachineFiles;
use Tetrix\AiBridge\Transfers\TransferRefused;
```

## Upload: browser → machine

```php
public function upload(Request $request, MachineFiles $files)
{
    $connection = /* the chat's Connection (a bridge connection) */;
    $workingDir = /* the chat's working folder: one of the machine's workspaces */;

    try {
        $file = $files->upload(
            $connection,                                  // or its connection_key
            $request->getContent(true),                   // the raw body, as a stream
            (int) $request->header('Content-Length'),     // exact size, required
            rawurldecode((string) $request->header('X-File-Name')),
            $workingDir,
            $request->header('Content-Type'),
        );
    } catch (TransferRefused $e) {
        return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], $e->status);
    }

    // Keep these: fileId is the only way to ask for the file back.
    return response()->json($file->toArray());
    // ['path' => '/work/repo/file-uploads/report.pdf', 'name' => 'report.pdf',
    //  'size' => 48213, 'sha256' => '…', 'file_id' => '0ec0a8e9-…']
}
```

The file lands in `<workingDir>/file-uploads/<name>` on the machine (the bridge adds
`-2`, `-3` on a name collision and returns the name it used; it creates the folder with
a `.gitignore` of `*`). Nothing is written on the server. The call returns when the
machine has confirmed the file (size and SHA-256 checked on both sides), or throws.

**Send the upload as `PUT` with a raw body**, not multipart and not `POST`. PHP-FPM
reads a whole `POST` body into a temp file before the script starts; any other method
is read as the script asks for it, which is what lets the bytes stream. Put the file
name in a header (percent-encoded: headers are Latin-1) and the type in `Content-Type`.

Check before you start, so the composer can say why not instead of failing late:
`ConnectionStatus::for($connection)` gives `connected`, `capabilities` (must contain
`file_uploads`), `attachment_limits['max_file_bytes']` (the machine's per-file cap) and
`workspaces` (where it may put files). The serve process checks all of these again.

## Download: machine → browser

```php
public function show(Request $request, MachineFiles $files, ChatFile $file)
{
    try {
        return $files->download(
            $file->connection,            // the machine it is on
            $file->device_file_id,        // the fileId the upload (or attachment event) gave you
            $file->name,
            $file->mime_type,
            $request->header('Range'),    // seeking and resuming work
            head: $request->isMethod('HEAD'),
            disposition: 'attachment',    // 'inline' only for types you render safely
        );
    } catch (TransferRefused $e) {
        abort($e->status, $e->getMessage());
    }
}
```

The response is a `StreamedResponse` with status 200/206/416, `Content-Length`,
`Content-Range` and `Accept-Ranges` from the machine's answer, plus defensive headers
(`nosniff`, `Content-Security-Policy: sandbox`, `private, no-store`, and
`X-Accel-Buffering: no` so nginx does not spool it). The machine serves only files it
recorded itself (received uploads, and files the assistant handed back in `device`
mode, whose `attachment` event carries `file_id`), by that id, never by path; a file
edited or replaced since is refused (`file_changed`).

**Files the assistant hands back** (`bridge__attach_file`) are uploaded to the app's
attachment store by default. Set `AI_BRIDGE_HANDED_BACK=device` (`ai-bridge.transfers.handed_back`)
to keep them on the machine instead: the welcome then says `config.attachments: device`,
and each `attachment` stream event carries `id: null`, `path` and `file_id`, which you
store and later pass to `download()` exactly like an upload's.

For anything other than a browser response (hashing, a preview), use the lower level:

```php
$stream = $files->open($connection, $fileId, range: 'bytes=0-1023');
// $stream->status, ->size, ->start, ->end, ->length
$stream->pipe(fn (string $bytes) => /* ... */ true);   // or $stream->read()
```

## When it does not work: `TransferRefused`

`$e->status` is the HTTP status to answer with; `$e->reason` is stable; the message is
a sentence for the person. The reasons:

| reason | status | meaning |
|---|---|---|
| `not_connected` | 409 | The machine is not connected. |
| `unsupported` | 409 | Its bridge predates file transfers (0.18). |
| `upload_too_large` | 413 | Over the machine's `max_file_bytes` (its `--attachment-max-mb`, default 25 MB). |
| `length_required`, `invalid_request`, `size_mismatch` | 411 / 400 | The request itself is wrong. |
| `working_dir_not_allowed`, `working_dir_not_found`, `upload_refused`, `upload_failed`, `upload_cancelled` | 422 | The machine refused or failed (message is the machine's). |
| `file_unknown`, `file_gone` | 410 | The machine has no such file any more. |
| `file_changed` | 409 | The file was edited or replaced since it was recorded. |
| `pickup_timeout`, `stalled`, `not_confirmed`, `no_answer`, `never_started`, `timeout` | 504 | Something stopped moving (30 s to start, 60 s of silence). |
| `machine_gone`, `machine_stopped`, `upload_mismatch`, `file_refused`, `file_failed`, `unreachable` | 502 | The machine went away, misbehaved, or the serve process is not running. |
| `client_gone` | 499 | The browser went away. |

## How it works, and why this way

```
browser ──PUT──▶ nginx ──▶ PHP-FPM worker ──internal HTTP──▶ ai-bridge:serve ◀──GET ?transfer=id── machine
                                              (POST /api/upload,           (bridge token, one-time id,
                                               relay token, streamed)       over the public WS location)
```

The worker and the machine each open one request to the **serve process**, which pipes
bytes from one to the other with backpressure and keeps nothing: a slow machine pauses
the worker's socket, which blocks the worker's write, which stops it reading the
browser. Downloads are the mirror image (`POST /api/file-read`; the machine POSTs the
bytes). The control frames (`upload_offer`, `upload_sent`, `upload_abort`,
`upload_done`, `file_read`, `file_read_result`, `file_read_cancel`) ride the WebSocket
the serve process already holds.

Why the serve process and not two FPM workers meeting over Redis: FPM cannot read a
machine's `POST` as it arrives (PHP reads it whole first), two workers would be held per
transfer, and every byte would pass through Redis. The serve process is non-blocking,
already holds the machine's socket, and already answers plain HTTP on the WebSocket's
port. The one-time URL the machine is sent is **the public WebSocket URL plus
`?transfer=<id>`**: nginx's `location = /api/ai-bridge/ws` matches on the path only, so
no new route has to be opened. The URL must be on the origin the bridge connected to
(the bridge refuses any other, and plain http off loopback); it is derived from
`ai-bridge.server.public_url`, else `APP_URL`. Override with `AI_BRIDGE_TRANSFER_URL`.

Costs: one FPM worker is held for the length of each transfer (set a generous
`request_terminate_timeout`; the methods call `set_time_limit(0)`), and the machine has
to be connected when the file is picked.

## Web server settings

Functionally nothing is required beyond the existing WebSocket location. For true
streaming (no temp files on the proxies, no size cap from the proxy):

- the location that serves the upload route: `fastcgi_request_buffering off;`
- the WebSocket location (`= /api/ai-bridge/ws`): `proxy_request_buffering off;`
  (the machine POSTs downloads there; with buffering on, nginx collects the whole file
  before the serve process sees a byte, which works but does not stream);
- a TLS proxy in front (proxy-nginx) buffers request bodies and caps them at its
  `client_max_body_size` (`--max-body-size`, 256 MB by default): raise it to the largest
  file you want to move in either direction.
