---
status: accepted
date: 2026-10-16
supersedes:
superseded_by:
revisit_when: the next major version, when ai-bridge.csrf.routes is to become the default; or when Laravel's own CSRF middleware changes shape again (it was renamed PreventRequestForgery in 13)
---
# CSRF enforced by the package on state-changing upstream routes; tool_call_id required on the browser route

## Context

The upstream routes added in 0.19 (`POST /ai-bridge/connections/{id}/mcp`, `POST` and `DELETE
…/upstreams/{server}/sign-in`) run in the `api` group plus `ai-bridge.route_middleware`, which
checks no CSRF token, and `tool_call_id` on the `mcp` route was optional (0008). A colleague's
audit of an app using the package found that with session auth on these routes (`web`, the
cookie + session stack the README recommends for session resolvers, Sanctum's stateful SPA auth)
and SameSite=Lax cookies, any page on a same-site subdomain could make a signed-in person's
browser call an app-visible tool on an upstream of their own bridge, or start and forget
sign-ins. The README told hosts to add CSRF themselves; that is easy to miss. Approved by Jasper
Bauer (owner).

## Decision

- The package checks CSRF itself, with middleware `ai-bridge.csrf:<scope>` on its routes,
  whatever `route_middleware` the app chose. It checks a request that rides on cookies: a
  started session and at least one cookie. Such a request must carry the session's token
  (`X-CSRF-TOKEN`, `_token`, or `X-XSRF-TOKEN`), compared by a subclass of Laravel's own CSRF
  middleware (`PreventRequestForgery` on 13, `VerifyCsrfToken` on 12) that only drops the
  unit-test skip and the cookie side effect, so the app's global exceptions and Laravel 13's
  `Sec-Fetch-Site` origin check apply. Mismatch: 419 JSON. A request without a session or
  without cookies (bearer tokens, server-to-server) passes: nothing a forging page could add.
- Scope `upstreams` (the three routes above) is on by default (`ai-bridge.csrf.upstreams`);
  scope `routes` (every other state-changing browser route) is opt-in
  (`ai-bridge.csrf.routes`) and becomes the default in the next major.
- `tool_call_id` is required on `POST …/mcp` (422 `tool_call_id_required`). The PHP API keeps it
  optional for trusted server code; a host relaying a browser's request must pass it. This
  narrows 0008's "optional `tool_call_id`" for the route only; 0008 otherwise stands.

## Consequences

- A front end calling these routes with session cookies must send the token; the reference chat
  UI does (its `csrf-token` attribute, the meta tag or the `XSRF-TOKEN` cookie).
- Route callers that omitted `tool_call_id` now get 422; called out in the CHANGELOG.
- Without `laravel/framework` (no Laravel CSRF middleware class) the check fails closed with 419.
- With `web` in `route_middleware` the token is checked twice (the `web` group's own middleware
  and this one); harmless.
