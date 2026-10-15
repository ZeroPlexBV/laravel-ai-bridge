# Changelog

Release notes for earlier versions are on the GitHub releases page of
tetrixdev/laravel-ai-bridge. This file starts with 0.16.0-RC1.

## [Unreleased] — to be released as 0.18.0 (ZeroPlex fork)

From here the package is the ZeroPlex fork, published as **`zeroplex/laravel-ai-bridge`**. It
`replace`s `tetrixdev/laravel-ai-bridge` and keeps the `Tetrix\AiBridge` namespace, so an
application switches by changing its `require` and adding the ZeroPlexBV `vcs` repository.

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
