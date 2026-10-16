---
status: accepted
date: 2026-10-03
supersedes:
superseded_by:
revisit_when:
---
# One bridge record: bridge_version remembered offline, self_update live only

## Context

Merging the ZeroPlex fork (PR #39) met upstream's PR #38 head-on: both recorded `bridge_version`
from `hello`. PR #38 had kept `bridge_version` and `self_update` live only (flat keys, no
migration), reasoning that a remembered version is what a self-update makes out of date. The fork
kept `bridge_version` inside the `bridge` record cached in `ai_bridge_connections.last_bridge`,
and ZeroPlex Studio relies on seeing it while a machine is off.

## Decision

Both live only in the one `bridge` record built by `MessageHandler::bridgeInfoFromHello()`.
`bridge_version` follows the fork: remembered from the last connection while the machine is off;
a self-updated bridge reconnects with a new `hello`, which replaces it. `self_update` follows
upstream: false whenever the bridge is not connected.

## Consequences

- `ConnectionStatus::for()` returns every key from both sides; `/api/status` also returns the
  flat keys, and `ConnectionStatus` accepts them from an older serve process during rolling deploys.

## Options considered

- Keep PR #38's live-only `bridge_version`: rejected, because Studio shows a machine is behind
  while it is switched off.
