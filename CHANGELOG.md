# Changelog

Release notes for earlier versions are on the GitHub releases page of
tetrixdev/laravel-ai-bridge. This file starts with 0.16.0-RC1.

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
