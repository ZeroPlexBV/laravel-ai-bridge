---
status: accepted
date: 2026-10-14
supersedes:
superseded_by:
revisit_when: an action in a card has to run where only the bridge can reach (on the machine)
---
# MCP App card buttons go through the host's doorway, never tool calls

## Context

D09346 (Dragan Jovanov, ZeroPlexBV fork PR #3) lets a tool name a `ui://` view that the chat draws
as a card, for example with a Confirm button. Some buttons must be the person's own act
(confirming a grant), which the assistant must not be able to perform. The design was reworked
twice after plan review on 2026-10-14 (commits 9ccaca1, cca107c).

## Decision

A button press in a card is the host's concern: the host (the chat, `zeroplex/ai`) handles it
through one checked and recorded doorway to the application's action handlers, behind the
person's sign-in. It never becomes a tool call and never travels over the bridge. This package
only makes the card appear: it declares the view, serves its HTML (`AiBridge::views()`) and hands
the host the tool's result (`tool_result.ui`).

## Consequences

- No bridge upgrade was needed for buttons, and the package has no action API.
- Showing a card changes nothing; a tool with a view only returns data (`ToolResult::withView()`).

## Options considered

- Calls from the view as tool calls run as the person (`callFromView`, tool `visibility`, a
  `ToolContext::origin()`): dropped, because anything that exists as a tool can be called by the
  assistant itself.
- Per-view action routes registered in the package (`actions:` naming POST routes): dropped in
  favour of one doorway in the host, with no extra confirmation step.
