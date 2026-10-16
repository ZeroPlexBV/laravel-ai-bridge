---
status: accepted
date: 2026-10-16
supersedes:
superseded_by:
revisit_when:
---
# A view's mcp_request is pinned to its server via tool_call_id

## Context

An upstream tool's view makes its own MCP requests, relayed by `AiBridge::mcpRequest()` and
`POST /ai-bridge/connections/{id}/mcp` to the bridge as `mcp_request`. Review of PR #42 (commit
2cf0f67) found that, unpinned, anyone who can call the API reaches any server and any tool the
upstream lets views call.

## Decision

Only `resources/read` of a `ui://` uri and `tools/call` are relayed; anything else is refused
before it leaves the server, as is a bridge without `hello.mcp_apps`. An optional `tool_call_id`
holds the request to that call's recorded view: another server is `refused`, an unrecorded view is
`unknown_view`. Hosts are told to take `server` from the view's own `ui.server` and pass
`tool_call_id`. The route is throttled to 30 requests a minute and each request fires
`McpRequestRelayed`.

## Consequences

- The lookup checks the cache first (`upstream_oauth.view_ttl`), then the connection's recorded
  blocks.
- A bridge without a connection row gives its views `connection_id: null`; those cannot be
  relayed through the route.
