---
status: accepted
date: 2026-10-03
supersedes:
superseded_by:
revisit_when: the oldest bridge that can update itself is no longer 0.24.0, or bridges accept ranges or tags
---
# The desired bridge version is strict semver and never below 0.24.0

## Context

Bridge 0.24.0 can update itself to a version the server names in `welcome`
(`desired_bridge_version`, config `ai-bridge.bridge.desired_version`). PR #38, by jfbauer.

## Decision

Accept only strict semver (no `v`, build metadata, range, tag or URL), compared by full semver
precedence, and never below `DesiredBridgeVersion::FLOOR` (`0.24.0`, so `0.24.0-rc.1` is below it).
A refused value is logged once per process and treated as unset, so the key is left out of `welcome`.

## Consequences

- Rolled out by restarting the serve process, since bridges read it when they connect.
- No `bridge_version_matches` helper: what it should return when either side is null is unclear.

## Options considered

- No floor: rejected, because pinning a machine to a version that cannot update itself would
  leave it stuck there.
