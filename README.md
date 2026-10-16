# Laravel AI Bridge

[![Latest version](https://img.shields.io/github/v/tag/tetrixdev/laravel-ai-bridge?label=version)](https://github.com/tetrixdev/laravel-ai-bridge/tags)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net)
[![Laravel 12+](https://img.shields.io/badge/Laravel-12%2B-red.svg)](https://laravel.com)

A unified AI streaming interface for Laravel. Connect any Chat Completions-compatible provider (OpenAI, Anthropic, Groq, Ollama, etc.) or local CLI tools (Codex, Claude, Gemini) to your app through a single, normalized streaming pipeline.

## What is this?

Laravel AI Bridge provides a unified streaming pipeline: **provider -> normalized events -> browser**. No matter where the AI response originates, your application receives the same `StreamEvent` objects through the same callback API. Three modes of operation cover every use case:

- **BYOK (Bring Your Own Key)** -- User provides an API key and endpoint. No local install needed.
- **Managed** -- Your app provides the API key. Same code path as BYOK, different config source.
- **CLI Bridge** -- User runs `npx @tetrixdev/ai-bridge` locally. Their CLI tools (Codex, Claude, Gemini) connect to your app via a dedicated WebSocket server.

## Installation

```bash
composer require tetrixdev/laravel-ai-bridge
```


Publish the config file:

```bash
php artisan vendor:publish --tag=ai-bridge-config
```

Publish the JavaScript client (optional):

```bash
php artisan vendor:publish --tag=ai-bridge-js
```

Add to your `.env`:

```env
# Required for all modes
AI_BRIDGE_TOKEN_SECRET=your-random-secret-here

# For BYOK / Managed mode
AI_BRIDGE_MODE=byok
AI_BRIDGE_ENDPOINT=https://api.openai.com
AI_BRIDGE_API_KEY=sk-...
AI_BRIDGE_MODEL=gpt-4o

# For CLI Bridge mode
AI_BRIDGE_MODE=bridge
AI_BRIDGE_SERVER_HOST=0.0.0.0
AI_BRIDGE_SERVER_PORT=8085
```

## Quick Start

A minimal BYOK example in three steps.

### 1. Configure `.env`

```env
# Generate with: openssl rand -hex 32
AI_BRIDGE_TOKEN_SECRET=REPLACE_WITH_OUTPUT_OF_openssl_rand_hex_32
AI_BRIDGE_MODE=byok
AI_BRIDGE_ENDPOINT=https://api.openai.com
AI_BRIDGE_API_KEY=sk-your-key
AI_BRIDGE_MODEL=gpt-4o
```

### 2. Create a Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tetrix\AiBridge\Facades\AiBridge;

class ChatController extends Controller
{
    public function stream(Request $request)
    {
        // NOTE (UX-001): conversation_id must stay CONSTANT across all messages in the
        // same conversation. Generating a new ID on every request (e.g. uniqid()) creates
        // a brand-new conversation each time, so the AI has no memory of previous messages.
        // Store the ID in the session and reuse it for follow-up messages.
        $conversationId = $request->input('conversation_id')
            ?? $request->session()->get('ai_conversation_id')
            ?? 'conv-' . Str::uuid();
        $request->session()->put('ai_conversation_id', $conversationId);

        return AiBridge::streamToResponse(
            conversationId: $conversationId,
            message: $request->input('message'),
            options: [
                'system_prompt' => 'You are a helpful assistant.',
            ],
        );
    }
}
```

### 3. Create a Blade View

```html
<div id="chat">
    <div id="messages"></div>
    <div id="loading" style="display:none; color: grey;">AI is thinking...</div>
    <div id="error" style="color: red; display: none;"></div>
    <input type="text" id="input" placeholder="Type a message...">
    <button id="send-btn" onclick="send()">Send</button>
</div>

<script src="/js/vendor/ai-bridge.js"></script>
<script>
// NOTE (UX-003): crypto.randomUUID() requires a secure context (HTTPS or localhost).
// Over plain HTTP on a LAN IP (common for staging / internal testing) it throws a
// TypeError and breaks the whole page. This helper falls back to a Math.random()-based
// ID generator so conversation IDs work everywhere.
function generateId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    // Fallback for non-secure contexts (plain HTTP). Not cryptographically strong,
    // but sufficient for uniquely identifying a conversation in the browser.
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

// NOTE (UX-001): Generate conversationId ONCE at page load and reuse it for all
// messages in this conversation. Regenerating on each send() creates a fresh
// conversation every time and the AI loses all context from previous messages.
const conversationId = 'conv-' + generateId();

const stream = new AiBridgeStream({
    mode: 'sse',
    url: '/ai-bridge/stream/sse',
});

// SEC: Use textContent instead of innerHTML — AI-generated content is untrusted
// and must not be inserted as raw HTML. If you need markdown rendering, use
// a sanitizer such as DOMPurify: el.innerHTML = DOMPurify.sanitize(rendered)
stream.on('text', (content) => {
    const el = document.getElementById('messages');
    el.textContent += content;
});

stream.on('done', () => {
    document.getElementById('messages').textContent += '\n\n';
    document.getElementById('loading').style.display = 'none';
    document.getElementById('send-btn').disabled = false;
    document.getElementById('input').disabled = false;
});

// UX-008: Also handle cancelled so UI is never left stuck after destroy() or cancellation
stream.on('cancelled', () => {
    document.getElementById('loading').style.display = 'none';
    document.getElementById('send-btn').disabled = false;
    document.getElementById('input').disabled = false;
});

stream.on('error', (code, message) => {
    const el = document.getElementById('error');
    el.textContent = `Error: ${message}`;
    el.style.display = 'block';
    document.getElementById('loading').style.display = 'none';
    document.getElementById('send-btn').disabled = false;
    document.getElementById('input').disabled = false;
});

function send() {
    const input = document.getElementById('input');
    if (!input.value.trim()) return;

    // Disable input during streaming and show loading indicator (UX-008)
    document.getElementById('send-btn').disabled = true;
    input.disabled = true;
    document.getElementById('loading').style.display = 'block';
    document.getElementById('error').style.display = 'none';

    // NOTE (UX-001): The conversation_id must remain STABLE across all messages in the
    // same conversation so the AI can remember the context. Generate it ONCE when the
    // page loads and reuse it for every send() call in this conversation session.
    // Using Date.now() here would create a new conversation on every message.
    stream.send({ message: input.value, conversation_id: conversationId });
    input.value = '';
}
</script>
```

## Modes of Operation

### BYOK (Bring Your Own Key)

The user provides an API key and endpoint for any Chat Completions-compatible provider. The server calls the API directly -- no local install required on the user's machine.

```php
$stream = AiBridge::stream('conv-1', 'Hello!', [
    'mode' => 'byok',
    'api_key' => $user->ai_api_key,        // per-user key
    'endpoint' => 'https://api.openai.com',
    'model' => 'gpt-4o',
    'system_prompt' => 'You are a game master.',
]);

$stream->onBlockDelta(fn ($event) => $this->appendToChat($event->data['content']));
$stream->onDone(fn ($usage) => $this->logUsage($usage));
$stream->start();
```

Works with any Chat Completions-compatible endpoint: OpenAI, Anthropic (via proxy), Groq, Together, Ollama, LM Studio, vLLM, etc.

### Managed

Identical to BYOK but the application provides its own API key. Users pay the app a subscription fee. No separate architecture needed -- same code path, different config source.

```env
AI_BRIDGE_MODE=managed
AI_BRIDGE_ENDPOINT=https://api.openai.com
AI_BRIDGE_API_KEY=sk-your-app-key
AI_BRIDGE_MODEL=gpt-4o
```

```php
// No per-user key needed -- uses the app's configured key
$stream = AiBridge::stream('conv-1', 'Hello!', [
    'system_prompt' => 'You are a helpful assistant.',
]);
$stream->start();
```

### CLI Bridge

The user installs the bridge locally via `npx @tetrixdev/ai-bridge`. It connects to your app's dedicated WebSocket server and proxies AI requests through their local CLI tools (using their existing subscriptions).

```env
AI_BRIDGE_MODE=bridge
AI_BRIDGE_TOKEN_SECRET=your-secret
AI_BRIDGE_SERVER_PORT=8085
```

Start the bridge server:

```bash
php artisan ai-bridge:serve
```

Generate a token for the user, then have them connect:

```bash
php artisan ai-bridge:token --user-id=42

# User runs on their machine:
npx @tetrixdev/ai-bridge --server=ws://yourapp.com:8085 --token=<JWT>
```

The server-side code is identical:

```php
$stream = AiBridge::stream('conv-1', 'Hello!', [
    'user_id' => $user->id,
]);
$stream->onBlockDelta(fn ($event) => echo $event->data['content']);
$stream->start();
```

#### Letting the assistant work in a repository

By default the bridge spawns every CLI in an empty directory, so a chat can talk
about code but cannot touch any. Two things turn that into a chat that does the
work.

**On the developer's machine**, the bridge is started with `--allow-dir`. It is
opt-in and empty by default, so nothing your server sends can point a bridge
somewhere its operator did not permit:

```bash
npx @tetrixdev/ai-bridge --server=wss://yourapp.com/ai-bridge/ws --token=<JWT> \
  --allow-dir ~/zp-studio=Studio
```

**In your app**, set the CLI isolation posture and give the conversation a
working directory:

```env
AI_BRIDGE_CLI_ISOLATION=workspace
```

```php
$conversation = Conversation::create([
    'mode' => 'bridge',
    'provider' => 'claude',
    // One of the paths the bridge advertised — see below.
    'working_dir' => '/Users/jasper/zp-studio/zeroplex-studio',
]);
```

The bridge advertises the directories it will accept when it connects, and they
arrive on the connection alongside its providers — so the app shows a picker
rather than asking anyone to type an absolute path:

```php
$status = app(ConnectionStatus::class)->for($connection);
$status['workspaces'];  // [['path' => '/Users/jasper/zp-studio', 'label' => 'Studio'], ...]
```

The list is empty for a bridge whose operator passed no `--allow-dir`, and for
one running a version that predates workspaces. Those mean the same thing:
naming a working directory will be refused, and so will `workspace` isolation —
the bridge gates the posture on the allow-list, precisely so that a server
cannot switch a shell on by sending a field.

Attachment URLs are built from `config('app.url')`, and the bridge only fetches
from the origin it is connected to. If your `APP_URL` is not the host the bridge
connects to, start the bridge with `--api <that origin>`.

The working directory is chosen once and then fixed: the bridge ties it to the
CLI session, and a later turn naming a different one is refused. `working_dir`
is therefore stored on the conversation and sent on every turn from there.

The stream endpoint also accepts it, for apps that create the conversation
before the developer has picked a workspace:

```php
POST /ai-bridge/conversations/{id}/stream
{ "message": "run the tests", "working_dir": "/Users/jasper/zp-studio/zeroplex-studio" }
```

That works on the **first** turn only. Afterwards, naming a different directory
is a 422 — and an **explicit empty string** clears the workspace, returning the
conversation to an ordinary chat and starting a fresh CLI session:

```php
{ "message": "never mind the repo", "working_dir": "" }
```

Clearing is the recovery path when a bridge comes back with a narrower
`--allow-dir` and a conversation is pinned to a directory it no longer allows —
without it, every turn on that conversation would 422 with no way out but
deleting it. Note that only an empty *string* clears: a JSON `null` is ignored,
so a client sending `working_dir: selectedDir` with nothing selected cannot
silently unpin a workspace and throw away the session.

> **`workspace` is not a sandbox, and the bridge documents this at length in
> [docs/isolation.md](https://github.com/tetrixdev/ai-bridge/blob/main/docs/isolation.md) —
> which also explains how `isolated` is enforced, and what it does not stop.**
> Once the CLI has a shell it runs code chosen by your server, on the
> developer's machine, as them — `cd ..` is one command away from wherever it
> started. What bounds it is that `--allow-dir` is opt-in, that the connection
> token is per person and revocable, and that a developer should only connect a
> bridge to a server they would give a shell to. Read
> [the bridge's security note](https://github.com/tetrixdev/ai-bridge#read-this-before-using-it)
> before enabling it.

#### Keeping bridges on one version

Set `AI_BRIDGE_DESIRED_VERSION` to the bridge version every machine should run:

```env
AI_BRIDGE_DESIRED_VERSION=0.24.1
```

The welcome then carries `desired_bridge_version`, and a bridge running as a
managed service (0.24.0 or newer) moves to exactly that version once it is idle
— upgrade or downgrade. Bridges read it when they connect, so restarting the
serve process (as a deploy does) is what rolls it out. The value must be a plain
semver version no lower than `0.24.0`, the first bridge that can update itself;
anything else is logged once and ignored rather than sent.

What each machine runs comes back on its status:

```php
$status = app(ConnectionStatus::class)->for($connection);
$status['bridge_version'];  // '0.24.1'; what it ran last time while offline; null if never reported
$status['self_update'];     // true only if this bridge is connected and will follow the desired version
$status['effort_levels'];   // thinking levels it honours as a turn's `effort` (Claude only); [] when none
```

`self_update` is `false` for a bridge started by hand, one whose operator opted
out, and every bridge older than 0.24.0 — those keep the version they run.

#### Attachments

A file attached in the chat does not travel over the WebSocket — the message cap
is 1 MB, so a screenshot would not fit, and would not fit as a dropped frame
rather than an error. The server hands the bridge a reference and the bridge
fetches it over HTTPS from this server, with its own connection token.

**The package stores nothing.** Where the bytes live is your app's business,
registered the same way conversations and connections are scoped:

```php
use Illuminate\Http\UploadedFile;
use Tetrix\AiBridge\Facades\AiBridge;

AiBridge::resolveAttachmentsUsing(
    fn (string $id, int|string $userId): ?\SplFileInfo =>
        Attachment::where('id', $id)->whereBelongsToBridgeUser($userId)->first()?->file()
);

AiBridge::storeAttachmentUsing(
    fn (UploadedFile $file, int|string $userId): array =>
        ['id' => Attachment::storeFor($userId, $file)->id]
);
```

The `$userId` is the subject of the bridge's connection token, **always as a
string** — the `connection_key` of the `Connection` row the conversation is
linked to, or the authenticated user's id when it is linked to none. The same
value is used when the references are built and when the bridge comes back to
fetch the file, so a `===` comparison inside your closure behaves the same on
both sides.

**Scoping the lookup to that user is your job and it matters**: returning a
file merely because the id exists would let any connected bridge fetch
anyone's uploads.

Attach files to a turn by id. The server builds the URL from its own route, so
a browser never nominates what the bridge fetches:

```php
POST /ai-bridge/conversations/{id}/stream
{ "message": "what does this invoice say?", "attachments": ["att_9f3c"] }
```

Without a resolver registered, downloads 404; without a store, files the
assistant tries to send back are refused with a 501, and the message travels
back to the model so it can say so rather than retry.

A file the assistant sends back arrives as an `attachment` stream event
carrying the id your store returned, so the UI renders it from your own
attachment store:

```php
$stream->onAttachment(function (array $attachment) {
    // ['id' => 'att_new', 'name' => 'report.md', 'mime_type' => …, 'size' => …,
    //  'description' => 'what the model said it is']
});
```

It is buffered like any other event, so a browser tailing the SSE stream —
including one that reconnected after a refresh — replays it too.

## Configuration

Full reference for `config/ai-bridge.php`:

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `mode` | `AI_BRIDGE_MODE` | `byok` | Active mode: `byok`, `managed`, or `bridge` |
| `token.secret` | `AI_BRIDGE_TOKEN_SECRET` | `null` | JWT signing secret (required) |
| `token.ttl` | `AI_BRIDGE_TOKEN_TTL` | `86400` | Token TTL in seconds (24h) |
| `websocket.heartbeat_interval` | -- | `30` | Seconds between ping/pong |

| `websocket.silence_timeout` | -- | `900` | Seconds a turn may produce **nothing** before the CLI is presumed wedged. This is the bound that stops a turn; `0` disables it. A long-running tool counts as silence — a `sleep 20` produces a 17-second gap — so this must exceed the longest build or test run you expect |
| `websocket.request_timeout` | -- | `86400` | Wall-clock backstop for one turn, in seconds. `0` means no ceiling |
| `chat_completions.endpoint` | `AI_BRIDGE_ENDPOINT` | `null` | Chat Completions API base URL |
| `chat_completions.api_key` | `AI_BRIDGE_API_KEY` | `null` | API key for BYOK/managed |
| `chat_completions.model` | `AI_BRIDGE_MODEL` | `null` | Model name (e.g. `gpt-4o`) |
| `chat_completions.max_tokens` | `AI_BRIDGE_MAX_TOKENS` | `4096` | Max response tokens |
| `chat_completions.allowed_models` | -- | `[]` | Model allowlist. Empty array allows all models. When non-empty, requests for a model not in the list are rejected with HTTP 422 |
| `server.host` | `AI_BRIDGE_SERVER_HOST` | `127.0.0.1` | Bridge WebSocket server bind address (set to `0.0.0.0` in Docker/multi-host setups) |
| `server.port` | `AI_BRIDGE_SERVER_PORT` | `8085` | Bridge WebSocket server port |
| `stream_store.default` | `AI_BRIDGE_STREAM_STORE` | `redis` | Per-turn event buffer driver. Ships with `redis` and `array` (tests); apps register their own via `StreamStore::extend()`. |
| `stream_store.redis.connection` | `AI_BRIDGE_STREAM_REDIS_CONNECTION` | `null` | Redis connection from `config/database.php`. `null` = app default. |
| `stream_store.redis.prefix` | `AI_BRIDGE_STREAM_REDIS_PREFIX` | `ai-bridge:stream` | Key prefix for buffer entries. |
| `stream_store.redis.ttl_streaming` | `AI_BRIDGE_STREAM_TTL_STREAMING` | `3600` | TTL (s) while a turn is live; refreshed on every event. |
| `stream_store.redis.ttl_completed` | `AI_BRIDGE_STREAM_TTL_COMPLETED` | `1800` | TTL (s) once a turn has terminated; bounds how long a recent page-load can replay. |
| `stream_store.poll_interval_ms` | `AI_BRIDGE_STREAM_POLL_MS` | `100` | SSE long-poll interval while a turn is streaming. |
| `stream_store.keepalive_interval_s` | `AI_BRIDGE_STREAM_KEEPALIVE_S` | `30` | SSE keepalive comment cadence (must beat intermediate proxy idle timeouts). |
| `stream_store.max_connection_s` | `AI_BRIDGE_STREAM_MAX_CONNECTION_S` | `600` | Per-SSE-connection lifetime ceiling; the browser auto-reconnects via `Last-Event-ID` after. |
| `logging.channel` | `AI_BRIDGE_LOG_CHANNEL` | `null` | Log channel for the bridge relay path. `null` uses the app's default channel; point it at a dedicated channel (e.g. a `daily` channel with its own retention) to keep bridge logs separate. Falls back to the default channel if the named one is undefined. |
| `logging.verbose` | `AI_BRIDGE_LOG_VERBOSE` | `false` | When `true`, also log per-event detail (every stream event, relayed payloads) at `debug` level. Useful in development; noisy in production. |
| `cli.isolation` | `AI_BRIDGE_CLI_ISOLATION` | `isolated` | How much the CLI on the developer's machine may do. `isolated` — server-declared tools only, no shell, no edits; the right posture when end users can send chat messages. `workspace` — the CLI also gets its own file and shell tools, inside the directory the request named, while the operator's own MCP servers, hooks and plugins stay out. `native` — everything on, including the operator's environment; never appropriate when the server is reachable by end users. Anything unrecognised falls back to `isolated`. `workspace` needs a bridge started with `--allow-dir` and a `working_dir` on the conversation, and **is not a sandbox** — see [Letting the assistant work in a repository](#letting-the-assistant-work-in-a-repository). |
| `cli.local_path` | `AI_BRIDGE_CLI_LOCAL_PATH` | `null` | Absolute path to an `ai-bridge` repo checkout. When set **and `APP_ENV=local`**, the "Add a CLI bridge" command runs that checkout's build (`node <path>/dist/cli.js`) instead of `npx @tetrixdev/ai-bridge@latest` — for testing CLI changes without an npm publish. Build the checkout first (`npm run build`). |
| `bridge.desired_version` | `AI_BRIDGE_DESIRED_VERSION` | `null` | The `@tetrixdev/ai-bridge` version every bridge should run, sent as `desired_bridge_version` in the welcome. Each connected bridge running as a managed service (0.24.0+, reporting `self_update: true`) moves to **exactly** this version once idle — upgrade or downgrade. Takes effect when the serve process restarts and bridges reconnect, as on a deploy. A plain semver version (`0.24.1`, `1.0.0-rc.2`; no `v`, build metadata, range, tag or URL), never below `0.24.0`, the first bridge that can update itself. A malformed or below-floor value is logged once as an error and treated as unset. `null`/empty = no opinion. Additive: older bridges ignore the field. |
| `streaming.suppress_thinking_blocks` | `AI_BRIDGE_SUPPRESS_THINKING` | `true` | Suppress AI chain-of-thought / thinking blocks from SSE output and the per-turn buffer. Set to `false` only when intentionally displaying AI reasoning to users. |

> **Upgrading from 0.12 or earlier with a published `config/ai-bridge.php`:**
> the package merges defaults only for keys your file does not already have, so
> a published `'request_timeout' => 300` still wins — and the bridge honours it
> as a **wall clock**, which kills a turn that has been working for five
> minutes. That is the bug this release fixes, and a published config keeps it.
> Add `'silence_timeout' => 900` and raise `request_timeout` (or set it to `0`
> to opt out of a ceiling entirely).


### Relay-path logging

When a bridge-mode chat hangs on "Thinking", the bridge relay log is the first
place to look. A healthy turn logs `relaying conversation message to bridge`,
then `relayed request to bridge server`, then a terminal `relayed stream done`
(or `relayed stream error` with the cause). With `logging.verbose` on, every
stream event and the relayed payload are logged too.

Point `logging.channel` at a dedicated channel to keep these out of the main
app log and give them their own retention — e.g. in `config/logging.php`:

```php
'ai-bridge' => [
    'driver' => 'daily',
    'path' => storage_path('logs/ai-bridge.log'),
    'days' => env('AI_BRIDGE_LOG_DAYS', 7),
],
```

then set `AI_BRIDGE_LOG_CHANNEL=ai-bridge`.

## Streaming to Browser

Two methods for delivering AI responses to the browser.

### SSE (Server-Sent Events)

Returns an SSE HTTP response. Simplest approach -- no extra infrastructure needed.

```php
// In your controller
public function stream(Request $request)
{
    return AiBridge::streamToResponse(
        conversationId: $request->input('conversation_id'),
        message: $request->input('message'),
        options: [
            'system_prompt' => $request->input('system_prompt', ''),
        ],
    );
}
```

Or use the built-in endpoint:

```
POST /ai-bridge/stream/sse
Content-Type: application/json

{
    "conversation_id": "conv-123",
    "message": "Hello!",
    "system_prompt": "You are a helpful assistant."
}
```

The response is `text/event-stream` with normalized events. The first event is always
`conversation_id`, carrying the server-generated conversation ID — capture it and send it
back with follow-up messages to continue a multi-turn conversation:

```
data: {"event":"conversation_id","data":{"conversation_id":"conv-123"}}

data: {"event":"block_start","data":{"block_type":"text","block_index":0}}

data: {"event":"block_delta","data":{"block_type":"text","block_index":0,"content":"Hello"}}

data: {"event":"block_delta","data":{"block_type":"text","block_index":0,"content":"!"}}

data: {"event":"block_stop","data":{"block_type":"text","block_index":0}}

data: {"event":"done","data":{"usage":{"prompt_tokens":10,"completion_tokens":5}}}

data: [DONE]
```

### Buffered streaming (refresh-safe)

For persisted conversations, use the buffered streaming path. The server
writes every event to a short-lived per-turn buffer (Redis by default);
the browser tails it over SSE. Native `EventSource` reconnection via
`Last-Event-ID` makes refresh, tab-switch and network blip recover the
in-flight reply for free — without losing tokens or restarting the turn.

```php
// In your controller — returns immediately with a request_id
public function send(Request $request)
{
    $conversation = AiBridge::conversationsQuery($request)
        ->whereKey($request->input('conversation_id'))
        ->firstOrFail();

    $requestId = AiBridge::startConversationStream(
        $conversation,
        $request->input('message'),
    );

    return response()->json([
        'status' => 'started',
        'request_id' => $requestId,
    ]);
}
```

Or use the built-in endpoint, which the bundled chat UI uses:

```
POST /ai-bridge/conversations/{id}/stream
Content-Type: application/json

{ "message": "I search the room" }

→ 200 { "status": "started", "request_id": "..." }
→ 409 { "error": "conflict", ... } when a turn is already in flight
```

Then tail the per-turn buffer from the browser using native
`EventSource`:

```javascript
const es = new EventSource(`/ai-bridge/streams/${requestId}/events`, { withCredentials: true });
['block_start','block_delta','block_stop','tool_call','tool_result','done','error','cancelled']
    .forEach(name => es.addEventListener(name, e => {
        const data = JSON.parse(e.data);
        // ...render
    }));
```

On page load, check whether a turn is in flight and re-attach. The
conversation payload (`GET /ai-bridge/conversations/{id}`) carries
`streaming_request_id` when a turn is live:

```javascript
const conv = await fetch(`/ai-bridge/conversations/${id}`).then(r => r.json());
if (conv.streaming_request_id) {
    // EventSource resumes from `Last-Event-ID` automatically on reconnect;
    // for a first attach on page load, replay from the start.
    new EventSource(`/ai-bridge/streams/${conv.streaming_request_id}/events`, { withCredentials: true });
}
```

Stop an in-flight turn:

```
POST /ai-bridge/streams/{requestId}/abort
```

## JavaScript Client

A lightweight vanilla JS module that wraps the HTTP endpoints with the
same refresh-safe semantics the bundled chat UI uses. Publish it first:

```bash
php artisan vendor:publish --tag=ai-bridge-js
```

This copies `ai-bridge.js` to `resources/js/vendor/ai-bridge.js`.

**Using with Vite (recommended):** Import it in your `resources/js/app.js`:

```js
import './vendor/ai-bridge.js';
```

**Manual approach:** Copy the file to `public/js/vendor/ai-bridge.js` and include with a script tag:

```html
<script src="/js/vendor/ai-bridge.js"></script>
```

### Buffered mode (default — recommended)

For conversation-based streams. POSTs the message, then tails the
per-turn buffer over native `EventSource`. Refresh, tab-switch and
network blip recover the in-flight reply automatically via
`Last-Event-ID`.

```javascript
const stream = new AiBridgeStream({ mode: 'buffered', api: '/ai-bridge' });

stream.on('text', (content) => { /* append to chat UI */ });
stream.on('thinking', (content) => { /* show reasoning */ });
stream.on('tool_call', (data) => { /* show tool usage */ });
stream.on('done', (usage) => { /* finalize, show token counts */ });
stream.on('error', (code, message) => { /* show error */ });

// 'cancelled' is a terminal event — handle it alongside 'done' and 'error' so UI
// cleanup (hiding spinners, re-enabling inputs) still runs if the stream is cancelled.
stream.on('cancelled', () => { /* reset UI */ });

stream.send({ conversationId: 42, message: 'I cast fireball' });

// On page load: if a turn is already in flight on this conversation,
// re-attach to its buffer (replays everything emitted so far).
//   const conv = await fetch(`/ai-bridge/conversations/${id}`).then(r => r.json());
//   if (conv.streaming_request_id) stream.attach(conv.streaming_request_id);

// Cancel the in-flight turn server-side.
//   stream.abort();
```

### SSE mode (one-shot, no conversation row)

For non-conversation use — a direct `text/event-stream` against
`/ai-bridge/stream/sse`. No resumption; a refresh loses the response.
Use buffered mode if you need refresh-safety.

```javascript
const stream = new AiBridgeStream({ mode: 'sse', url: '/ai-bridge/stream/sse' });

stream.on('conversation_id', (id) => { /* persist for follow-ups */ });
stream.on('text', (content) => { /* append */ });
stream.on('done', (usage) => { /* finalize */ });

stream.send({ message: 'Hello!', conversation_id: 'temp-123' });
```

### With Alpine.js

```html
<div x-data="chat()" x-init="init()">
    <div x-text="messages"></div>
    <input x-model="input" @keydown.enter="send()" :disabled="isStreaming">
    <button @click="send()" :disabled="isStreaming">Send</button>
</div>

<script src="/js/vendor/ai-bridge.js"></script>
<script>
// NOTE (UX-003): crypto.randomUUID() requires a secure context (HTTPS or localhost)
// and throws over plain HTTP on a LAN IP. This helper falls back to a Math.random()
// based ID so conversation IDs work everywhere, including HTTP staging environments.
function generateId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

function chat() {
    return {
        messages: '',
        input: '',
        isStreaming: false,
        stream: null,
        conversationId: null,
        init() {
            // NOTE (UX-001): Generate the conversation_id ONCE here and reuse it for
            // every send() call. Regenerating it per message (e.g. with Date.now())
            // starts a fresh conversation each time, so the AI loses all prior context.
            this.conversationId = 'conv-' + generateId();
            this.stream = new AiBridgeStream({
                mode: 'sse',
                url: '/ai-bridge/stream/sse',
            });
            this.stream.on('text', (content) => {
                this.messages += content;
            });
            this.stream.on('done', () => {
                this.messages += '\n\n';
                this.isStreaming = false;
            });
            this.stream.on('cancelled', () => {
                this.isStreaming = false;
            });
            this.stream.on('error', (code, message) => {
                this.messages += `\n[Error: ${message}]\n`;
                this.isStreaming = false;
            });
        },
        send() {
            // UX: Prevent submitting while streaming or with empty input
            if (this.isStreaming || !this.input.trim()) return;
            this.isStreaming = true;
            // NOTE (UX-001): Reuse the conversationId generated once in init() so the
            // AI keeps context across every message in this conversation session.
            this.stream.send({
                message: this.input,
                conversation_id: this.conversationId,
            });
            this.input = '';
        },
    };
}
</script>
```

## Conversation Persistence

The package persists multi-turn conversations to the database so they can be
listed, resumed, and replayed. Persistence is always on — there is no opt-in
flag. Three tables are created by **auto-loaded** migrations (they are *not*
publishable — do not fork them): `ai_bridge_conversations`, `ai_bridge_messages`
and `ai_bridge_connections`, on the application's default database connection.

The tables are deliberately **not linked** to any of your tables. Your app
associates conversations/connections with its own users or sessions via its own
pivot tables, and tells the package which rows a request may see by registering
two scoping resolvers (e.g. in a service provider's `boot()`):

```php
use Tetrix\AiBridge\Facades\AiBridge;

AiBridge::resolveConversationsUsing(
    fn (Request $request) => $request->user()->conversations()->getQuery()
);
AiBridge::resolveConnectionsUsing(
    fn (Request $request) => $request->user()->connections()->getQuery()
);
```

Listen for `ConversationCreated` / `ConnectionCreated` to link a newly created
row to your owner model.

### Connection lifecycle events

The package dispatches synchronous events around connection lifecycle so your
app can keep its own state in step:

| Event | When | Use it to |
|-------|------|-----------|
| `ConnectionCreated` | a connection is registered via the HTTP API | link the new row to your owner/session model |
| `ConnectionDeleted` | a connection is deleted via the HTTP API | run non-database cleanup tied to the connection |

Each event carries the `Connection` and the live `Request`.

**Cascading the delete.** Give your owner pivot a cascading foreign key so its
rows are removed automatically when a connection is deleted — no listener
needed for the database side:

```php
Schema::create('user_connection', function (Blueprint $table) {
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('connection_id')
        ->constrained('ai_bridge_connections')
        ->cascadeOnDelete();
    $table->primary(['user_id', 'connection_id']);
});
```

With the cascade in place, listen for `ConnectionDeleted` only when you have
*other* work to do (e.g. notifying a service, clearing a cache). For deletes
that should be vetoed or cleaned up transactionally, you can also hook the
Eloquent `deleting` event on `Tetrix\AiBridge\Models\Connection`.

> **If your resolver reads the session** (e.g. `$request->session()`), the AI
> Bridge routes must run with the **full cookie + session middleware stack** —
> not `StartSession` alone. Configure `ai-bridge.route_middleware` accordingly:
>
> ```php
> 'route_middleware' => [
>     \Illuminate\Cookie\Middleware\EncryptCookies::class,
>     \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
>     \Illuminate\Session\Middleware\StartSession::class,
> ],
> ```
>
> Your web pages set an **encrypted** session cookie (via the `web` group).
> Without `EncryptCookies` on the AI Bridge routes, `StartSession` cannot
> decrypt that cookie and starts a brand-new session on every request — so
> session-scoped conversations and connections appear to vanish between
> requests. Apps that scope by an authenticated user instead (`$request->user()`)
> can use their normal auth middleware and are unaffected.

### HTTP API

| Method & path | Purpose |
|---------------|---------|
| `GET /ai-bridge/conversations` | List conversations (scoped + paginated) |
| `POST /ai-bridge/conversations` | Create a conversation |
| `GET /ai-bridge/conversations/{id}` | Conversation + messages + `tools_stale` flag |
| `DELETE /ai-bridge/conversations/{id}` | Delete a conversation |
| `POST /ai-bridge/conversations/{id}/stream` | Start a turn — returns `{request_id}`; the browser tails `/streams/{rid}/events` |
| `GET /ai-bridge/streams/{rid}/status` | Status snapshot of an in-flight or recently-completed turn |
| `GET /ai-bridge/streams/{rid}/events` | SSE tail of the per-turn event buffer; resumes by `Last-Event-ID` |
| `POST /ai-bridge/streams/{rid}/abort` | Cancel an in-flight turn. Sets a flag; the WebSocket process acts on it at the turn's next event, or at the next heartbeat when the turn has gone quiet — so a stop takes effect within the heartbeat interval (30s by default) at worst. **Stopping the CLI itself needs `@tetrixdev/ai-bridge` 0.11.0 or newer**: older bridges ignore `cancel`, so the chat ends the turn but the CLI runs on to the end on the operator's machine |
| `GET /ai-bridge/connections` | List connections with their advertised providers/models + live `connected` flag, plus the `bridge_version` a connected bridge runs and whether it will follow `bridge.desired_version` (`self_update`), and for a bridge whether it passes MCP Apps through (`mcp_apps`) |
| `POST /ai-bridge/connections` | Register a CLI bridge or BYOK connection |
| `PATCH /ai-bridge/connections/{id}` | Rename a connection |
| `POST /ai-bridge/connections/{id}/regenerate` | Rotate a bridge's token (revokes the old one, disconnects any live bridge) |
| `DELETE /ai-bridge/connections/{id}` | Delete a connection (disconnects any live bridge) |
| `GET /ai-bridge/attachments/{id}` | Stream one attachment **to a connected bridge**. Guarded by the bridge connection token, not the browser session; served through `AiBridge::resolveAttachmentsUsing()` |
| `POST /ai-bridge/attachments` | Accept a file the assistant produced, same guard, stored through `AiBridge::storeAttachmentUsing()` |

History injection retains prior text and tool calls/results but excludes
thinking blocks; switching provider/model/mode mid-conversation is supported.

## Reference Chat UI

A drop-in ChatGPT-style chat component ships with the package:

```blade
<x-ai-bridge::chat api="/ai-bridge" />
```

It is a thin wrapper that renders an `<ai-bridge-chat>` **Web Component**. The
component uses **Shadow DOM**, so it is fully isolated — it cannot conflict with
your app's CSS framework or JavaScript (no global Tailwind, no global Alpine).
Its pre-built bundle is served by the package; your app needs no build toolchain.
It is entirely optional — the backend is fully usable without it.

### Customizing the chat UI

The component is a reference implementation — you are never locked into it.

1. **Build your own UI (recommended for anything beyond light tweaks).** Every
   piece of logic lives server-side, so a custom UI is lightweight: render JSON
   from the HTTP API above, POST messages to the stream endpoint, tail the
   returned `request_id`'s buffer over `EventSource`. Use any stack — Blade,
   Livewire, Vue, React. The stream emits these events (each as an SSE
   `event: <name>` line with a JSON `data:` payload): `block_start`,
   `block_delta` (`{block_type, content}`), `block_stop`, `tool_call`
   (`{tool_name, parameters, tool_call_id}`, plus `provider_tool_call_id`, the
   CLI's own id for a server-run call when the bridge reports it), `tool_result`
   (`{tool_call_id, result}`, plus `is_error`, `parent_tool_use_id` and `ui`
   when present), `done` (`{usage}`), `error`, `cancelled`. A `tool_result` that
   carries `ui` is a card to draw: see
   [Tools with a view (MCP Apps)](#tools-with-a-view-mcp-apps).

   The bundled component (`resources/dist/ai-bridge-chat.js`) is the working
   reference for everything a client needs to do: API calls, EventSource
   lifecycle, `Last-Event-ID` resumption on conversation-open, reassembling
   `block_*` events into rendered messages, and watchdog handling for stalled
   turns. Read it as the example rather than re-deriving the contract.

2. **Fork the component.** Copy `resources/dist/ai-bridge-chat.js` from the
   package into your app, adjust it, and point your own `<script>`/element at
   it. It is a single self-contained file with no build step.

## Tool System

Register tools that the AI can call during a conversation. Tools work across all three modes.

> **Every parameter must be described.** A tool registered with a parameter
> that has no (or an empty) `description` is rejected with an
> `InvalidArgumentException` at registration time. This applies to every
> registration path below. Tool names must start with a letter and contain only
> letters, digits, underscores, or hyphens (max 64 characters).

### Register with a Closure

The `parameters` argument is a raw JSON Schema object. Each entry under
`properties` must include a non-empty `description`.

```php
// In a service provider's boot() method
AiBridge::registerTool(
    name: 'roll_dice',
    description: 'Roll one or more dice',
    parameters: [
        'type' => 'object',
        'properties' => [
            'sides' => ['type' => 'integer', 'description' => 'Number of sides on each die'],
            'count' => ['type' => 'integer', 'description' => 'Number of dice to roll'],
        ],
        'required' => ['sides'],
    ],
    handler: function (array $params) {
        $sides = $params['sides'];
        $count = $params['count'] ?? 1;
        $rolls = [];
        for ($i = 0; $i < $count; $i++) {
            $rolls[] = random_int(1, $sides);
        }
        return ['rolls' => $rolls, 'total' => array_sum($rolls)];
    },
);
```

A tool that takes no parameters passes an empty array (`parameters: []`).

### Register with the Structured `AbstractTool` API (recommended)

Extending `AbstractTool` is the recommended way to define a tool. Instead of
hand-writing a JSON Schema, you declare each parameter as a `ToolParameter`.
Because `ToolParameter` requires a non-empty description, it is impossible to
define a tool with an undescribed parameter -- the schema is generated for you.

```php
use Tetrix\AiBridge\Tools\AbstractTool;
use Tetrix\AiBridge\Tools\ToolParameter;

class LookupCharacterTool extends AbstractTool
{
    public function name(): string
    {
        return 'lookup_character';
    }

    public function description(): string
    {
        return 'Look up a character in the database';
    }

    protected function defineParameters(): array
    {
        return [
            new ToolParameter(
                name: 'name',
                type: 'string',
                description: 'The full name of the character to look up',
            ),
            new ToolParameter(
                name: 'realm',
                type: 'string',
                description: 'Which realm to search in',
                required: false,
                enum: ['mortal', 'fae', 'celestial'],
            ),
        ];
    }

    public function handle(array $params): mixed
    {
        return Character::where('name', $params['name'])->first()?->toArray();
    }
}

// Register it
AiBridge::registerToolHandler(new LookupCharacterTool());
```

`ToolParameter` accepts the JSON Schema types `string`, `integer`, `number`,
`boolean`, `array`, and `object`. `defineParameters()` returns a list of them,
and `AbstractTool` turns that list into the JSON Schema the API expects via the
`final` `parameters()` method.

### Register with a `ToolHandler` Class

You can also implement the `ToolHandler` interface directly and build the JSON
Schema yourself. Every property must still include a non-empty `description`.

```php
use Tetrix\AiBridge\Contracts\ToolHandler;

class LookupCharacterTool implements ToolHandler
{
    public function name(): string { return 'lookup_character'; }
    public function description(): string { return 'Look up a character in the database'; }
    public function parameters(): array {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'The full name of the character'],
            ],
            'required' => ['name'],
        ];
    }
    public function handle(array $params): mixed {
        return Character::where('name', $params['name'])->first()?->toArray();
    }
}

// Register it
AiBridge::registerToolHandler(new LookupCharacterTool());
```

### Tools with a view (MCP Apps)

A tool can name an interactive **view** that the chat draws next to its call: a card with the
tool's result and, for example, a Confirm button. It follows the MCP Apps standard, so the view is
plain HTML that talks to its host through `postMessage`. Needs `@tetrixdev/ai-bridge` 0.27.0 or
newer on the machine and a CLI that reports its call ids (Claude Code); anywhere else the tool
still runs, it just shows no view. See [PROTOCOL.md](PROTOCOL.md#tools-with-a-view-mcp-apps).

**What happens when the person presses a button in a card is not this package's concern.** The
host (the chat, `zeroplex/ai`) handles it through its own doorway, behind the person's sign-in;
it never becomes a tool call and never travels over the bridge. This package only makes the card
appear: it declares the view, serves its HTML and hands the host the tool's result.

**1. Register the view** (a `ui://` resource the server serves; the HTML is rendered per request):

```php
use Tetrix\AiBridge\Facades\AiBridge;

AiBridge::views()->register(
    uri: 'ui://studio/grant-request',
    html: fn () => view('grants.card')->render(),
    csp: [],                                   // MCP Apps `_meta.ui.csp`; empty = nothing external
);
```

**2. Give the tool the view, and return a result for both readers.** The model gets the text; the
view gets the structured content too.

```php
use Tetrix\AiBridge\Tools\AbstractTool;
use Tetrix\AiBridge\Tools\ToolResult;

class ShowGrantRequestTool extends AbstractTool
{
    public function name(): string { return 'show_grant_request'; }
    public function description(): string { return 'Show the person a grant request to confirm'; }
    public function view(): ?string { return 'ui://studio/grant-request'; }   // null = no view (default)

    protected function defineParameters(): array { /* grant_id */ }

    public function handle(array $params): mixed
    {
        $grant = GrantRequest::findOrFail($params['grant_id']);

        return ToolResult::withView(
            text: "Grant request {$grant->id} is shown to the person.",
            structured: $grant->toCardData(),
        );
    }
}
```

**Return `ToolResult::withView()`, not a plain array or string.** A tool with a view that returns
anything else still gets a card, but one with only that value as text and no structured content,
and nothing fails: the server only logs it at debug level. A `ToolResult::withView()` with an
empty `structured` looks the same.

A `ToolHandler` class gets the same by implementing `Tetrix\AiBridge\Contracts\HasView`
(`view()`); a closure tool passes `view:` to `registerTool()`. Showing the card is all the tool
does: it changes nothing.

**3. The host draws it.** The stream's `tool_result` for such a call carries `ui`
(`resource_uri`, `tool_name`, `arguments`, `result`), in the stream store and on the recorded
block; `onToolResult` receives it as a fifth argument (`?array $ui`, null for every other result).
The host fetches the HTML with `AiBridge::views()->render($ui['resource_uri'])`
(`['html' => …, 'csp' => […]]`; an unknown URI throws `ViewNotFoundException`, which a host
treats as a call with no view: old history can name a view a later release removed or renamed).
A view over 256 KB is not kept, so its call shows no card. Whether a machine
can show views at all is `$status['mcp_apps']` on its connection status (true once its bridge
announced `mcp_apps` at hello).

### Tools from the machine's own MCP servers (upstream MCP Apps and sign-in)

A bridge started with `--mcp-upstreams` offers the CLI tools of MCP servers on the operator's
side (see ai-bridge's `docs/mcp-upstreams.md`). They run on the machine, never here, but this
package carries what a host needs to draw their views and to sign in to the ones that use
OAuth. Needs a bridge that announces `mcp_apps` (views) and `mcp_upstream_auth` (sign-in) at
hello; pairs with ai-bridge PR #60. Drawing the card, asking the person's consent for a view's
CSP or permissions, and what a button means stay with the host. See
[PROTOCOL.md](PROTOCOL.md#upstream-mcp-apps-and-oauth-sign-in).

**Views.** An upstream tool's `tool_result` carries `ui` like a server tool's, plus `server`
(the upstream's name) and `connection_id` (the connection whose bridge made the call): on the
stream event, the recorded block and `onToolResult`'s fifth argument, under the same 256 KB
and turn-budget rules. A view's own requests go back through that bridge:

```php
use Tetrix\AiBridge\Facades\AiBridge;
use Tetrix\AiBridge\Upstreams\McpRequestFailed;

$connection = AiBridge::connectionsQuery($request)->findOrFail($ui['connection_id']);

try {
    // $toolCallId: the tool_call_id of the result whose view is asking.
    $html = AiBridge::mcpRequest($connection, $ui['server'], 'resources/read', ['uri' => $ui['resource_uri']], toolCallId: $toolCallId);
    $again = AiBridge::mcpRequest($connection, $ui['server'], 'tools/call', ['name' => 'refresh', 'arguments' => ['city' => 'Utrecht']], toolCallId: $toolCallId);
} catch (McpRequestFailed $e) {
    // $e->errorCode: unknown_server, unavailable, timeout, refused, unsupported, upstream_error,
    // or invalid_request, unknown_view, not_connected, mcp_apps_unsupported, unreachable
}
```

**Pin the request to the view.** Take `server` from the view's own `ui.server`, never from what
the view's frame asks for, and pass the call's `tool_call_id`: the request is then refused
(`refused`) unless `server` is the one recorded for that view on that connection, or
(`unknown_view`) when no such view was recorded there. Without `tool_call_id`, any server and
any tool the upstream lets views call are reachable by whoever can call the API. A bridge
connected without an `ai_bridge_connections` row (under a user id rather than a connection key)
gives its views `connection_id: null`; such views cannot be relayed through the route.

Only `resources/read` of a `ui://` uri and `tools/call` are relayed; anything else is refused
before it leaves this server. It works from a web worker or a job (it reaches the serve process
over its internal API, like `sendTurnInput()`), waits up to `ai-bridge.server.mcp_request_timeout`
(65 s), and fires `Tetrix\AiBridge\Events\McpRequestRelayed` (user, connection, server, method,
tool or uri, outcome) for an audit trail. The same is `POST /ai-bridge/connections/{id}/mcp`
(`{server, method, params, tool_call_id}`), throttled to 30 a minute.

> **CSRF: these JSON routes have none of their own.** Like every route under
> `ai-bridge.route_middleware` they run in the `api` group, which does not check a CSRF token.
> With session-cookie auth (`['auth']` and a browser) a hostile page could POST to
> `…/mcp` or `…/sign-in` as the signed-in person. Use Sanctum's stateful SPA auth
> (`auth:sanctum`, which checks the XSRF token for first-party requests), a token in a header,
> or add CSRF-checking middleware to `ai-bridge.route_middleware`; or do not expose them and
> call `AiBridge::mcpRequest()` / `AiBridge::upstreams()` from your own CSRF-protected routes.

**Sign-in.** When an upstream needs the person to sign in, the bridge sends an authorization URL;
the package keeps it as a pending sign-in and fires `UpstreamAuthRequired` (in the serve process)
so the host can show a "Connect kaizen" card. The person opens the URL in their browser, signs in
at the upstream, and is sent back to the package's callback route
(`ai-bridge.upstream-oauth.callback`, `/ai-bridge/upstream-oauth/callback`, behind `web` + `auth`),
which checks the sign-in is theirs, hands the one-time code to their bridge, waits for the result
and shows "Connected — you can close this tab" (view `ai-bridge::upstream-oauth.result`, publish
`ai-bridge-views` to change it). `UpstreamAuthCompleted` says how it ended. The bridge keeps and
refreshes the tokens; this application never sees one.

```php
$upstreams = AiBridge::upstreams();

$upstreams->status($connection);              // connected, mcp_apps, mcp_upstream_auth, upstreams[], pending[]
$upstreams->pending($connection);             // [{connection_id, server, authorization_url, expires_at, reason}]
$upstreams->startSignIn($connection, 'kaizen'); // ['ok' => true, 'authorization_url' => …] or ['ok' => false, 'code' => …]
$upstreams->forget($connection, 'kaizen');    // the bridge forgets the tokens; true when sent
```

| JSON route (behind `route_middleware`) | Name |
|---|---|
| `GET /ai-bridge/connections/{id}/upstreams` | `ai-bridge.upstreams.index` |
| `GET /ai-bridge/connections/{id}/upstreams/pending` | `ai-bridge.upstreams.pending` |
| `POST /ai-bridge/connections/{id}/upstreams/{server}/sign-in` | `ai-bridge.upstreams.sign-in` |
| `DELETE /ai-bridge/connections/{id}/upstreams/{server}/sign-in` | `ai-bridge.upstreams.forget` |
| `POST /ai-bridge/connections/{id}/mcp` | `ai-bridge.upstreams.mcp` |

Each upstream is `{server, transport, origin, auth, state, tools, error}` (`state`: `connecting`,
`connected`, `needs_auth`, `unavailable`); the list is also on `ConnectionStatus::for()` as
`upstream_status`, next to `mcp_upstream_auth`, and `UpstreamStatusChanged` fires when it changes.

Two things to get right:

- **The cache must be shared.** The serve process writes pending sign-ins and the web process
  reads them (`ai-bridge.upstream_oauth.cache_store`, default the default store): Redis or the
  database, not `array`.
- **`APP_URL` must be the address browsers use** (or set `AI_BRIDGE_UPSTREAM_OAUTH_REDIRECT_URI`).
  The callback URL is sent to bridges in `welcome` and registered with the authorization server,
  character for character. `AI_BRIDGE_UPSTREAM_OAUTH=false` switches the route and the URL off.

### Listening for Tool Calls

```php
$stream = AiBridge::stream('conv-1', 'Roll 2d6 for damage');
$stream->onToolCall(function (string $name, array $params, string $callId) {
    // Tool execution happens automatically if registered.
    // This callback is for UI updates / logging.
    Log::info("AI called tool: {$name}", $params);
});
$stream->start();
```

### Listening for Tool Results

A tool that runs on the operator's own machine — the CLI's shell, file reader
or editor — never reaches your server any other way. This callback is the only
account of what it did.

```php
$stream->onToolResult(function (string $callId, mixed $result, ?bool $isError) {
    // $callId pairs this to the tool_call block that produced it.
    //
    // $isError is null when the provider did not report an outcome. Absent
    // never means "succeeded" — do not read failure out of the text either, as
    // a tool legitimately printing "Error: no matches" is indistinguishable
    // from one that failed.
    //
    // $result is null when the tool returned nothing, which is different from
    // not having returned yet.
    Log::info("tool {$callId} finished", ['failed' => $isError]);
});
```

A result too large for one WebSocket frame arrives in chunks and is reassembled
before this callback runs, so you always receive it whole. See `PROTOCOL.md`
for the size bounds that apply on the way in and on the way to the database.

### Helpers (sub-agents)

When the assistant hands work to a helper — Claude's `Agent` tool — the
helper's blocks and results arrive in the same stream as the main assistant's.
`parent_tool_use_id` says which is which: it is on a helper's `block_start`
data and is the fourth argument of `onToolResult`, holding the `tool_call_id`
of the call that spawned the helper. Absent (null) means the main assistant,
so a consumer that ignores it sees what it always saw. The recorder stores it
on each helper `tool_call` block, so a reload can still nest the call.

`onTask` reports each helper's life, with the bridge's data passed through
whole — `phase` (`started`, `progress`, `heartbeat`, `updated`, `finished`),
`task_id`, `tool_use_id` (the key to group by) and whatever that phase carries:

```php
$stream->onToolResult(function (string $callId, mixed $result, ?bool $isError, ?string $parentToolUseId) {
    // $parentToolUseId: null for the main assistant's own calls.
});

$stream->onTask(function (array $task) {
    // A background helper (`is_backgrounded: true` at `started`) keeps
    // reporting after the main assistant has finished its reply. It is done
    // only when a `finished` phase says so — never when its spawning call's
    // tool_result arrives, which for a background helper is at once.
    if ($task['phase'] === 'finished') {
        Log::info('helper finished', [
            'helper' => $task['tool_use_id'] ?? null,
            'status' => $task['status'] ?? null,
            'tokens' => $task['usage']['total_tokens'] ?? null,
        ]);
    }
});
```

The turn's helper totals arrive on `done` as `$meta['subagent_stats']`, in the
CLI's own shape. Both reach a browser through the buffered SSE stream. See
`PROTOCOL.md` for every field.

### A message sent while a turn runs

A bridge turn can keep its input open, so a message the person types while it
runs — typically after the assistant has answered while its helpers are still
working — reaches the assistant in that turn instead of waiting for all of it
to end. Opt in per turn with `accepts_input` (server-side only; background
tasks are on for such a turn):

```php
$requestId = AiBridge::startConversationStream($conversation, $message, ['accepts_input' => true]);

// Later, from another request, when the person sends something else:
if (AiBridge::inputOpen($requestId)) {
    $result = AiBridge::sendTurnInput($requestId, $clientMessageId, $text);
    // $result->status: 'accepted' | 'rejected'
    // $result->reason: 'turn_not_running' (start a new turn with it),
    //                  or anything else (hold it until the turn ends)
}
```

`inputOpen()` is true only once the bridge has confirmed the mode on its ack,
and only while the turn runs; an older bridge never confirms it. Accepted means
queued, not read: the stream carries `user_input` (`message_id`) at the moment
the assistant takes the message in, and `main_state` (`working` / `idle`) says
whether a message now is read straight away or after the current step. Both
are buffered and replay by index (`onUserInput`, `onMainState` on a
`StreamHandler`). A stopped turn lists the ids it never read in
`pending_inputs` on `cancelled`. See `PROTOCOL.md`, "Turn Input".

A bridge may close a turn's input before the turn ends (ai-bridge 0.25+,
capability `input_closed`): the stream then carries `input_closed`
(`data.reason`, `idle` today), after which `inputOpen()` is false and a
`sendTurnInput()` would be answered `turn_ending`, so hold the message for the
next turn. The same moment is in the turn's stream metadata (`input_open` =>
false, `input_closed_reason`), on `onInputClosed` of a `StreamHandler`, and
fired in the serve process as `Tetrix\AiBridge\Events\TurnInputClosed`
(`userId`, `requestId`, `reason`).

### Thinking level (`effort`)

A bridge turn can name how hard Claude thinks, Claude Code's `--effort`. Pass it
per turn as `effort`; it is not stored on the conversation. It applies to Claude
conversations only (ai-bridge 0.32.0+): Codex and Gemini ignore it, so offer a
level picker only where the provider is Claude:

```php
$requestId = AiBridge::startConversationStream($conversation, $message, ['effort' => 'high']);
```

It reaches the bridge as `ai_request.options.effort`, beside `options.model`,
and is sent again if the turn is re-issued after a lost CLI session. Without it
nothing is sent and the CLI runs at its own default. The conversation route
takes it too: `POST /ai-bridge/conversations/{id}/stream` with `effort`.

Which levels a machine honours it says itself, at `hello` (ai-bridge with
`effort_levels`):

```php
$levels = app(ConnectionStatus::class)->for($connection)['effort_levels'];
// ['low', 'medium', 'high', 'xhigh', 'max']; [] when the bridge does not honour effort
```

An empty list means the machine ignores the option (every bridge older than the
field, and BYOK). A level not in the list is ignored by the bridge, and the turn
runs at the default. Like `capabilities`, the list is remembered from the last
connection while the machine is off; check `connected`.

A `model` passed the same way is also for that turn only, and wins over the
conversation's stored `model` without changing it. The assistant row and the
turn's stream metadata record the model the turn ran with.

### What the turn cost

`onDone` receives a second argument with everything the provider reported
beyond the token counts — the model that actually ran, the CLI version, the
stop reason, cost and durations, and any tool calls the operator refused.

`subtype` is worth reading when an answer arrives empty: it is how the CLI
itself classified the ending (`success`, `error_during_execution`,
`error_max_turns`), and `stop_reason` is null on several of those paths, so
without it a blank message is all your UI has to go on.

```php
$stream->onDone(function (?array $usage, array $meta = []) {
    // $usage includes the CACHE counters, which dominate a resumed
    // conversation — a total built from input/output alone will not reconcile
    // against your provider's bill.
    Log::info('turn finished', [
        'cache_read' => $usage['cache_read_input_tokens'] ?? null,
        'model' => $meta['model'] ?? null,
        'cost_usd' => $meta['cost_usd'] ?? null,
        'denied' => $meta['permission_denials'] ?? [],
    ]);
});
```

A turn that ends in an **error** never fires `onDone` — the error is its
terminal. What it cost is still recorded, and readable afterwards:

```php
$stream->lastDoneUsage();   // token counts, success or failure
$stream->lastDoneMeta();    // model, cost_usd, num_turns, …
```

### Rate limit notices

Informational and non-terminal: the turn continues.

```php
$stream->onRateLimit(function (string $provider, array $info) {
    Log::notice("{$provider} reported a rate limit", $info);
});
```

## Bridge Server

The `ai-bridge:serve` command starts a dedicated WebSocket server for CLI bridge connections. It runs on its own port and speaks the AI Bridge Protocol — separate from any other realtime infrastructure your app may use.

```bash
php artisan ai-bridge:serve
```

Options:

```
--host=0.0.0.0    Bind address (default: from config or 0.0.0.0)
--port=8085       Port number (default: from config or 8085)
```

The server:
- Accepts WebSocket connections from bridge clients (`npx @tetrixdev/ai-bridge`)
- Validates JWT tokens from the `?token=` query parameter
- Routes AI request/response messages through the `MessageHandler`
- Tracks connections via `BridgeConnectionManager`
- Handles graceful shutdown on SIGINT/SIGTERM

### Running bridge mode — one background process is required

Bridge mode needs **one** long-running process alongside your web server,
because the AI response arrives at a different process than the one
handling the browser request (see the data-flow diagram in
[Architecture](#architecture)):

| Process | Command | Why it is needed |
|---------|---------|------------------|
| **AI Bridge server** | `php artisan ai-bridge:serve` | Accepts the WebSocket connection from the user's local `npx @tetrixdev/ai-bridge` CLI bridge, and writes stream events from it into the per-turn buffer the browser tails over SSE. |

It must run continuously — under a process manager (Supervisor), as a
dedicated container, or via Octane in development. A typical Supervisor
setup:

```ini
[program:ai-bridge-serve]
command=php /app/artisan ai-bridge:serve --host=0.0.0.0 --port=8085
autostart=true
autorestart=true
```

A working Redis (used for the per-turn buffer by default) is also
required. **BYOK / Managed mode needs neither** — those stream over SSE
directly from the web process, so a plain web server plus Redis is enough.

## Artisan Commands

| Command | Description |
|---------|-------------|
| `ai-bridge:serve` | Start the dedicated WebSocket server for CLI bridge connections |
| `ai-bridge:token` | Generate a JWT connection token for testing |
| `ai-bridge:test` | Send a test request through the configured mode |

### `ai-bridge:serve`

```bash
php artisan ai-bridge:serve --port=8085
```

Starts the bridge WebSocket server. Bridge clients connect to `ws://host:port?token=<JWT>`.

### `ai-bridge:token`

```bash
php artisan ai-bridge:token --user-id=42 --ttl=3600
```

Generates a JWT token for testing bridge connections without needing a full auth flow.

### `ai-bridge:test`

```bash
# Test BYOK mode
php artisan ai-bridge:test "What is 2+2?"

# Test managed mode
php artisan ai-bridge:test "Hello!" --mode=managed

# Test bridge mode (requires active bridge connection)
php artisan ai-bridge:test "Hello!" --mode=bridge
```

Sends a test request and displays streaming events in the console.

## Architecture

```mermaid
graph LR
    Browser["Browser"]
    Laravel["Laravel App"]
    Buffer["Per-turn buffer (Redis)"]
    API["Chat Completions API"]
    Bridge["Bridge (local)"]
    CLI["CLI Tools"]

    Browser <-->|SSE tail (Last-Event-ID)| Laravel
    Laravel <--> Buffer
    Laravel <-->|HTTPS| API
    Laravel <-->|WebSocket :8085| Bridge
    Bridge --> CLI

    subgraph "BYOK / Managed"
        API
    end

    subgraph "CLI Bridge"
        Bridge
        CLI
    end
```

```
Browser <--SSE--> Laravel App <--WebSocket--> Bridge (local) --> CLI tools
                       |
                  per-turn buffer (Redis)
                       |
                  Chat Completions API (BYOK/Managed)
```

**Data flow (BYOK/Managed):**
1. Browser POSTs the message to `/conversations/{id}/stream`; Laravel returns `{request_id}` and runs the upstream AI call in a `terminating()` callback.
2. Each streamed event is written to the per-turn buffer keyed by `request_id`.
3. Browser opens `EventSource('/streams/{rid}/events')` and tails the buffer; native `Last-Event-ID` reconnect makes refresh/blip recover the in-flight reply.

**Data flow (CLI Bridge):**
1. Browser POSTs the message; the web worker relays an `ai_request` to the `ai-bridge:serve` process over its internal HTTP API.
2. The serve process sends the request over WebSocket to the user's local bridge; events come back asynchronously into the same serve process.
3. Each event is written to the per-turn buffer (and the assistant message is persisted at terminal).
4. Browser tails the buffer over SSE, same as BYOK/Managed — uniform shape across modes.

## Files on the machine

Bridge 0.18+ receives files a person picks in a chat and hands back files it holds, streamed through the serve process and never stored on the server: `MachineFiles::upload()` / `MachineFiles::download()`. See [docs/file-transfers.md](docs/file-transfers.md).

## Protocol

The WebSocket protocol between the server and CLI bridge is documented in [PROTOCOL.md](PROTOCOL.md).

## License

MIT. See [LICENSE](LICENSE).
