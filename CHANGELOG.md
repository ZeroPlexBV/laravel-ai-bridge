# Changelog

Release notes for earlier versions are on the GitHub releases page of
tetrixdev/laravel-ai-bridge. This file starts with 0.16.0-RC1.

## [Unreleased] — to be released as 0.21.0

Hardens the upstream routes added in 0.19 (decision 0009).

### Security

- **CSRF checked by the package on the state-changing upstream routes.**
  `POST /ai-bridge/connections/{id}/mcp` and `POST`/`DELETE
  /ai-bridge/connections/{id}/upstreams/{server}/sign-in` run in the `api` group, which checks no
  CSRF token; with session auth on those routes (`web`, the cookie + session stack, Sanctum's
  stateful SPA auth) a page on any same-site subdomain could make a signed-in browser call an
  upstream tool or start and forget sign-ins. A new middleware, `ai-bridge.csrf`, now requires
  the session's CSRF token (`X-CSRF-TOKEN`, `_token` or `X-XSRF-TOKEN`, compared by Laravel's own
  CSRF middleware on Laravel 12 and 13) on every request that rides on cookies, answering
  `419 {"code": "csrf_token_mismatch"}`. Requests without a session or without cookies (bearer
  tokens, Sanctum personal access tokens, server-to-server) pass unchanged.

### Changed

- **Breaking for route callers: `tool_call_id` is now required on
  `POST /ai-bridge/connections/{id}/mcp`.** Without it (missing, null or empty) the route answers
  `422 {"code": "tool_call_id_required"}` and relays nothing, so a browser's request is always
  held to the view that makes it. `AiBridge::mcpRequest()` / `UpstreamClient::mcpRequest()` keep
  `$toolCallId` optional for trusted server code; a host relaying a browser's request must pass
  it.
- The reference chat UI sends the CSRF token (`csrf-token` attribute set by
  `<x-ai-bridge::chat />`, else `<meta name="csrf-token">`, else the `XSRF-TOKEN` cookie) on
  every state-changing request.

### Added

- Config `ai-bridge.csrf.upstreams` (`AI_BRIDGE_CSRF_UPSTREAMS`, default `true`): switch the
  upstream routes' CSRF check off only when the app protects them itself.
- Config `ai-bridge.csrf.routes` (`AI_BRIDGE_CSRF_ROUTES`, default `false`): the same check on
  every other state-changing browser route (token, `stream/sse`, conversations POST/DELETE/stream,
  connections POST/PATCH/DELETE/regenerate, streams abort). Opt in now; it becomes the default in
  the next major version.

### Upgrading

- A front end calling `…/mcp` or `…/sign-in` with session cookies must send the CSRF token
  (e.g. `X-CSRF-TOKEN` from `<meta name="csrf-token">`; axios sends `X-XSRF-TOKEN` by itself),
  and must send `tool_call_id` on `…/mcp`.

## [Unreleased] — to be released as 0.20.0

### Added

- **Thinking level (`effort`), per turn.** A turn option `effort` (a string, Claude Code's
  `--effort`: `low` … `max`) is sent to the bridge as `ai_request.options.effort`, beside
  `options.model`, on the in-process and the PHP-FPM relay paths alike, and again when a turn is
  re-issued after `session_lost`. Absent, empty or not a string: nothing is sent and the turn runs
  as before. `POST /ai-bridge/conversations/{id}/stream` takes it as `effort` (422 unless it is
  1 to 16 lowercase letters); `StreamController` lets it through its option whitelist. Claude
  conversations only: the bridge (ai-bridge 0.32.0+) passes it to Claude Code as `--effort`, and
  Codex and Gemini ignore it.
- **`hello.effort_levels`.** The levels a bridge honours, recorded with its other hello facts and
  reported as `ConnectionStatus::for()['effort_levels']` (`list<string>`, `[]` when the bridge
  does not honour `effort`: BYOK and every bridge that predates the field), also as
  `ConnectionStatus::effortLevels()` and on `GET /ai-bridge/connections`. Remembered from the
  last connection while the machine is off, like `capabilities`.

### Changed

- **Behaviour change: a per-turn `model` now wins over the conversation's stored model.** A caller
  that passes `model` in a turn's options while the conversation has a stored `model` now gets the
  turn's model, where it used to get the stored one. `streamConversation()` /
  `startConversationStream()` used to overwrite a caller's `model` option with the
  conversation's `model` whenever one was stored, so a turn could not switch model without
  rewriting the conversation. The turn's own `model` is now sent when given, and the stored one
  otherwise (unchanged when the caller gives none). The conversation row is not changed; the
  assistant row and the turn's stream metadata record the model the turn ran with, and a
  `session_lost` re-issue sends it again instead of reverting to the stored one.

No migration, no config change.

## [Unreleased] — to be released as 0.19.0

Upstream MCP Apps and OAuth sign-in for the MCP servers a bridge connects to
(`--mcp-upstreams`). Pairs with tetrixdev/ai-bridge PR #60.

### Added

- **Upstream tool views.** A bridge's `tool_result.ui` naming a `server` (an upstream tool's
  view) is kept on the stream event, the recorded block and `onToolResult`'s fifth argument,
  under the same 256 KB and turn-budget rules as a server-built view, from the first frame of
  the call only, and stamped with `connection_id` (the connection that streamed it). A
  bridge-sent `ui` on a server tool's call is ignored.
- **`AiBridge::mcpRequest($connection, $server, $method, $params)`** (also
  `AiBridge::upstreams()->mcpRequest()`, `POST /ai-bridge/connections/{id}/mcp`): relays a
  view's `resources/read` (`ui://` only) or `tools/call` to the bridge as `mcp_request` and
  returns the `mcp_result`, or throws `Upstreams\McpRequestFailed` with its code. Works from
  any process through the serve process's new `POST /api/mcp`; refuses other methods locally
  and bridges without `hello.mcp_apps`. An optional `tool_call_id` holds the request to that
  call's view: a server other than the view's is refused (`refused`), an unrecorded view is
  `unknown_view`. Event `McpRequestRelayed` per request.
- **OAuth sign-in to upstreams** (bridge `hello.mcp_upstream_auth`): `welcome.upstream_oauth`
  with the callback URL; `upstream_status` recorded (`ConnectionStatus`: `mcp_upstream_auth`,
  `upstream_status`); `upstream_auth_required` kept as a pending sign-in in the cache; the
  callback route `ai-bridge.upstream-oauth.callback` (`web` + `auth`, 404 unknown or expired
  state, 403 someone else's) forwards `upstream_auth_code`, waits for `upstream_auth_result`
  and renders the overridable view `ai-bridge::upstream-oauth.result`.
  `AiBridge::upstreams()`: `status()`, `pending()`, `startSignIn()` (`upstream_auth_start`),
  `forget()` (`upstream_auth_forget`), and the JSON routes `ai-bridge.upstreams.index`,
  `.pending`, `.sign-in`, `.forget`. Events `UpstreamAuthRequired`, `UpstreamAuthCompleted`,
  `UpstreamStatusChanged`.
- Config: `ai-bridge.upstream_oauth.*` (`enabled`, `path`, `middleware`, `redirect_uri`,
  `callback_timeout`, `start_timeout`, `cache_store`, `view`, `view_ttl`) and
  `ai-bridge.server.mcp_request_timeout`. Serve-process endpoints `POST /api/mcp`,
  `/api/upstream/auth-start`, `/api/upstream/auth-code`, `/api/upstream/auth-forget`;
  `/api/status` reports `upstream_status`.

### Upgrading

- The pending sign-ins live in the cache and are written by the serve process: use a cache
  store both processes share (`AI_BRIDGE_UPSTREAM_OAUTH_CACHE_STORE`).
- The callback URL is built from `APP_URL`; set it to the public address, or set
  `AI_BRIDGE_UPSTREAM_OAUTH_REDIRECT_URI`. `AI_BRIDGE_UPSTREAM_OAUTH=false` turns it off.
- `GET /ai-bridge/connections` adds `mcp_upstream_auth` and `upstream_status` for a bridge.
- The new JSON routes, like the existing ones, check no CSRF token: with session-cookie auth,
  add CSRF protection (Sanctum stateful auth, or middleware) or call the PHP API from your own
  routes. `ai-bridge:serve` warns when the sign-in cache store is `array`.

## [Unreleased] — to be released as 0.18.0

The MCP App cards built in the ZeroPlexBV fork (D09346, released there as
`zeroplex/laravel-ai-bridge` 0.18.0) merged back, without the package rename: the package stays
**`tetrixdev/laravel-ai-bridge`**. An application on the fork can switch back by requiring
`tetrixdev/laravel-ai-bridge` again; the namespace (`Tetrix\AiBridge`) never changed.

### Added

- **Tools with a view (MCP Apps, ai-bridge 0.27+).** A tool may name a `ui://` view
  (`view()` / `HasView`, `registerTool(view:)`); its definition carries `_meta.ui.resourceUri`
  to the bridge. Views are registered in `AiBridge::views()` with their HTML and CSP. A button
  press in a view is the host's concern and never a tool call. The server keeps each such call's full result
  (`ToolResult::withView()`) under the CLI's `provider_tool_call_id`, and the stream's
  `tool_result` for that call carries `ui` (`resource_uri`, `tool_name`, `arguments`, `result`),
  also on the recorded message block (kept whole or, past the turn's storage budget, dropped with
  `ui_dropped_bytes` and `ui_dropped_reason`, `too_large` or `turn_budget_spent`; never cut). A view
  over 256 KB is not kept at all. `onToolResult` callbacks receive it as a fifth argument (`?array $ui`), `onToolCall` callbacks
  the `provider_tool_call_id` as a fourth (`?string`), and the stream's `tool_call` event carries it
  on the buffered and the direct SSE path (`streamToResponse()`, `POST /ai-bridge/stream/sse`) alike.
  A view's HTML is rendered with `AiBridge::views()->render($uri)`.
- **hello `mcp_apps`** recorded in `last_bridge` and reported on the connection status
  (`mcp_apps: bool`, also on `GET /ai-bridge/connections`).
- **`StoresStreamValues`** (`putValue` / `getValue`): small keyed values kept beside a turn, as long
  as the turn lives. Both bundled stream stores implement it; a custom driver that does not simply
  shows no views.

### Changed

- The recorder pairs a `tool_call` frame with its stream block by `provider_tool_call_id` when the
  bridge reports one, and by tool name only as a fallback.

## [Unreleased] — to be released as 0.17.0

The ZeroPlexBV fork (0.16.0-RC1, 0.16.0, 0.16.1 below) merged back into
tetrixdev/laravel-ai-bridge, together with upstream's own 0.16.0 (the desired bridge
version: `ai-bridge.bridge.desired_version`, `welcome.desired_bridge_version`, hello
`self_update`). The two 0.16.x lines are different releases that share version numbers, so
the merged package continues at **0.17.0**.

### Added

- **`input_closed` (ai-bridge 0.25+).** A bridge that closes a running turn's input sends the
  `input_closed` stream event (`data.reason`, `idle` today; any value accepted). The turn's
  stream metadata then says `input_open: false` and `input_closed_reason`, so
  `AiBridge::inputOpen()` turns false and the next message is held for a new turn instead of
  being answered `turn_ending`. The event is relayed and buffered like `main_state`
  (`StreamHandler::onInputClosed()`), and fired in the serve process as
  `Tetrix\AiBridge\Events\TurnInputClosed`. Hello `input_closed: true` is recorded as the
  `input_closed` capability (`ConnectionStatus::supports($connection, 'input_closed')`).
- From upstream 0.16.0: the desired bridge version and `self_update`, now also on
  `ConnectionStatus::for()` next to the fork's `bridge_version`, `attachment_limits` and
  `capabilities`.

### Changed

- `bridge_version` is stored once, in the connection's `bridge` record (with `self_update`).
  `bridge_version` is still reported from the last connection while a machine is off;
  `self_update` is live only.

No new migration beyond the fork's (`last_bridge`).

## [0.16.1] — 2026-10-02

### Fixed

- **A turn running for more than an hour stopped taking messages.** The Redis stream store
  renewed only a turn's event log as events came in. Its status and metadata kept the lifetime
  they got when the turn started (`ttl_streaming`, an hour by default), so a long turn (a helper
  working on) read as `not_found` while it was still writing events, and `inputOpen()` refused
  every message typed for it. In the chat, "Send now" said the reply was not taking messages
  while the assistant was free. Each event now renews the status and metadata too, while the
  turn is streaming. A turn that goes quiet still expires `ttl_streaming` after its last event,
  and an event after the end does not stretch a finished turn.

No migration, no config change.

## [0.16.0] — 2026-09-30

The final release of 0.16.0, identical in code to 0.16.0-RC1. It pairs with **zeroplex/ai 9.4.0**
and **ai-bridge 0.17.0 or newer** (0.21.0 recommended). Published from the ZeroPlexBV fork while the
changes wait to go upstream. Everything listed under 0.16.0-RC1 below applies; run the migration
(one new column) when upgrading from 0.15.0.

## [0.16.0-RC1] — 2026-09-29

A release candidate, published from the ZeroPlexBV fork while it waits to go upstream.
It catches the package up with **ai-bridge 0.21.0** and carries everything the chat overhaul
in zeroplex/ai 9.4 needs. Everything is additive: an application that asks for none of it
behaves as on 0.15.0. Run the migration (one new column).

### Added

- **Helpers are visible.** A helper assistant's frames carry `parent_tool_use_id`, a `task`
  event reports each helper's life (started, progress, finished), and `done` carries the
  turn's helper totals (`subagent_stats`). A helper's own prose is no longer stored as the
  main assistant's reply.
- **A message can reach a turn that is still running.** `options.accepts_input`, the
  bridge's `input_open`, a new internal route `POST /api/request/input`,
  `AiBridge::sendTurnInput()` / `inputOpen()`, and `user_input` / `main_state` stream events.
  Messages the assistant never read come back (`pending_inputs`, `TurnInputsReturned`) on a
  stop, an error, or after a dropped connection.
- **Files stream to and from the machine** (bridge 0.18+), through the serve process with
  backpressure and nothing stored on the server: `Transfers\MachineFiles::upload()` /
  `open()` / `download()`, `TransferRefused` with an HTTP status and a reason.
  `AI_BRIDGE_HANDED_BACK=device` keeps files the assistant hands over on the machine.
  See `docs/file-transfers.md`.
- **What a machine says about itself is kept.** Its bridge version, attachment limits and
  capabilities from `hello`, on `/api/status`, cached in the new
  `ai_bridge_connections.last_bridge` column (so an application can say a machine is behind
  while it is switched off), and through `ConnectionStatus::for()`, `bridgeVersion()`,
  `supports()`.
- **`bridge_prompt`** option (default / off / append / replace), sent on both paths and kept
  when a lost session is re-issued. `turn_ending` is passed through.
- **Usage rate limits are told apart**: a bridge's `rate_limited` reason and `retry_after`
  reach `AiBridge::usage()`.

### Fixed

- **A conversation's "turn running" marker ends with its turn**, on every ending: a serve
  process that shuts down ends its relayed turns (`server_restarting`) and tells the machines
  to stop them; an ending from the machine for a turn nobody here knows still ends it
  (`relay_lost`); and `ai-bridge:sweep-turn-markers`, scheduled every five minutes, clears
  markers left behind. Before, a restart mid-turn left the chat looking busy for ever.
- The unread messages a bridge names when it answers a stop that this side already ended are
  recorded, not dropped.
- The test suite lifts PHP's memory limit; the full run outgrew 128 MB (already on 0.15.0).

### New configuration

| Env | Default | What |
|---|---|---|
| `AI_BRIDGE_TURN_INPUT_TIMEOUT` | `5` | Seconds to wait for a machine to take a message into a running turn. |
| `AI_BRIDGE_TRANSFER_URL` | | The address machines use for file transfers, when it differs from the public WebSocket URL. |
| `AI_BRIDGE_HANDED_BACK` | `server` | `device` keeps handed-over files on the machine. |
| `AI_BRIDGE_SWEEP_TURN_MARKERS` | `true` | Schedule the marker sweep. |
| `AI_BRIDGE_TURN_MARKER_GRACE` | `600` | Seconds before a marker without a turn buffer is cleared. |

### Upgrading

Run `php artisan migrate` (`last_bridge`). If the serve process sits behind proxy-nginx,
read the note in the docs on 502/503 interception and response buffering. File transfers
need the web server to pass large request bodies through unbuffered with long timeouts.
