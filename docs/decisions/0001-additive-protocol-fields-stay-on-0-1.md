---
status: accepted
date: 2026-09-17
supersedes:
superseded_by:
revisit_when: a change cannot be made optional in both directions, so an old bridge or an old server would misread it
---
# Additive protocol fields stay on protocol version 0.1

## Context

The protocol version is exchanged in `hello.version`, and only its major number is enforced.
Workspaces, attachments and `workspace` isolation added fields in both directions. The date is
the earliest commit in this repository's history carrying the rule (the merge of PR #32); it may
have been decided slightly earlier.

## Decision

New wire fields and frames are additive and stay on `0.1`: absent means what it always meant,
both ends ignore what they do not recognise, and the server sends a new frame only to a bridge
that announced the matching `hello` field. The same was applied later to helper activity
and to upstream MCP Apps and sign-in ([PROTOCOL.md, Versioning](../../PROTOCOL.md#versioning)).

## Consequences

- Every new server-to-bridge frame needs a `hello` capability check before it is sent
  (`HELLO_CAPABILITIES`, `bridgeSupports()`, `mcp_apps`, `mcp_upstream_auth`).
- PROTOCOL.md keeps saying, per feature, that it does not bump the version.

## Options considered

- Bump the version per feature: rejected, because only the major number is enforced and a bump
  would refuse every bridge already installed in exchange for nothing.
