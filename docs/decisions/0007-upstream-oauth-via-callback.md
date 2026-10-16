---
status: accepted
date: 2026-10-16
supersedes:
superseded_by:
revisit_when:
---
# Upstream OAuth sign-in is relayed through a callback route; tokens stay on the bridge

## Context

A bridge started with `--mcp-upstreams` offers tools of MCP servers on the operator's side, some
behind OAuth. The person signs in in their browser, but the tokens are used on the machine.
PR #42 (jfbauer), paired with tetrixdev/ai-bridge PR #60.

## Decision

The bridge sends an authorization URL (`upstream_auth_required`), kept as a pending sign-in in the
cache. The authorization server redirects to this package's callback route
(`ai-bridge.upstream-oauth.callback`, `web` + `auth`), which checks the sign-in is the person's,
claims it once under a lock, forwards the one-time code as `upstream_auth_code` and waits for
`upstream_auth_result`. The bridge exchanges the code and keeps and refreshes the tokens; this
application never sees one.

## Consequences

- The redirect URI is built from `APP_URL` (or config), never from the request host, and must be
  stable across connections.
- The pending sign-ins need a cache store both processes share; `ai-bridge:serve` warns on `array`.
- Unknown and expired states both answer 404, someone else's 403, and nothing is forwarded.
