Documentation: follows Engram documentation standard v1.

# laravel-ai-bridge: notes for agents

Laravel package `tetrixdev/laravel-ai-bridge`: one streaming pipeline (provider -> normalised
events -> browser) for BYOK, managed and CLI-bridge modes. Start at [docs/README.md](docs/README.md).

## Commands

A dev host may have no PHP; run everything in the `composer:2` image:

```bash
docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app composer:2 install
docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app --entrypoint php composer:2 vendor/bin/pest
# smallest: one file or one test
docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app --entrypoint php composer:2 vendor/bin/pest tests/Unit/McpRequestTest.php
docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app --entrypoint php composer:2 vendor/bin/pest --filter='refuses a BYOK connection'
```

- The PostgreSQL migration tests skip without a PG host; CI runs them (`.github/workflows/`).
- `resources/dist/ai-bridge-chat.js` is a hand-maintained bundle with no build step; its
  Playwright tests are manual: [tests/Browser/README.md](tests/Browser/README.md).

## Non-negotiables

- Namespace `Tetrix\AiBridge`, package name `tetrixdev/laravel-ai-bridge`. Neither is renamed (0006).
- A button press in an MCP App card is never a tool call and never travels over the bridge; it is
  the host's doorway (0005).
- No token from an upstream ever reaches the server: the bridge keeps and refreshes upstream OAuth
  tokens; the server only relays the one-time code (0007).
- Before sending a frame newer than the protocol baseline, check the bridge announced the matching
  `hello` field (`mcp_apps`, `mcp_upstream_auth`, `HELLO_CAPABILITIES`, `bridgeSupports()`).
  New wire fields are additive and do not bump the protocol version (0001).
- The web process never holds a WebSocket: it reaches the `ai-bridge:serve` process through that
  process's internal HTTP API (`/api/*` in `src/Server/BridgeWebSocketServer.php`). Anything the
  serve process writes for the web process to read goes through Redis or a shared cache store.
- A view's `mcp_request` is pinned to the view's own server through `tool_call_id`; only
  `resources/read` of a `ui://` uri and `tools/call` are relayed (0008).
- Every wire change is documented in [PROTOCOL.md](PROTOCOL.md), the README section for it and
  [CHANGELOG.md](CHANGELOG.md) (Keep a Changelog, "Unreleased — to be released as X.Y.Z" until tagged).

## Where things live

- `src/AiBridgeManager.php`, `src/Facades/AiBridge.php`: the public PHP API.
- `src/Server/`: the serve process (WebSocket server and its internal HTTP API).
- `src/WebSocket/`: frame handling (`MessageHandler`) and per-bridge state (`BridgeConnectionManager`).
- `src/Streaming/`: stream handlers, the per-turn stream store (`Drivers/`), conversation recorder.
- `src/Tools/`: tool registry and views (`ViewRegistry`); `src/Upstreams/`: upstream views, `mcp_request`, OAuth.
- `src/Transfers/`: file uploads and downloads through the serve process.
- `src/Http/`, `routes/api.php`: JSON routes and the OAuth callback; `config/ai-bridge.php`: every setting.
- `tests/Unit/`: Pest suite; `tests/Conformance/`: shared tool-call scenarios.

## Load-bearing decisions

- 0001 Additive protocol fields stay on protocol version 0.1.
- 0002 The desired bridge version is strict semver and never below 0.24.0.
- 0003 One bridge record: `bridge_version` remembered offline, `self_update` live only.
- 0004 After merging the ZeroPlex fork's 0.16, numbering continues at 0.17.0.
- 0005 MCP App card buttons go through the host's doorway, never tool calls.
- 0006 The package keeps the name `tetrixdev/laravel-ai-bridge` after merging the fork's 0.18.
- 0007 Upstream OAuth sign-in is relayed through a callback route; tokens stay on the bridge.
- 0008 A view's `mcp_request` is pinned to its server via `tool_call_id`.

Full records: [docs/decisions/](docs/decisions/README.md).
