---
type: reference
---
# Documentation map

In suggested reading order.

1. [../README.md](../README.md) — what the package is, installation and quick start; the manual for application developers. Sections worth going straight to:
   [Modes of Operation](../README.md#modes-of-operation),
   [Configuration](../README.md#configuration),
   [Streaming to Browser](../README.md#streaming-to-browser),
   [Conversation Persistence](../README.md#conversation-persistence),
   [Tool System](../README.md#tool-system) (with [Tools with a view](../README.md#tools-with-a-view-mcp-apps) and [upstream MCP Apps and sign-in](../README.md#tools-from-the-machines-own-mcp-servers-upstream-mcp-apps-and-sign-in)),
   [Running bridge mode](../README.md#running-bridge-mode--one-background-process-is-required),
   [Keeping bridges on one version](../README.md#keeping-bridges-on-one-version),
   [Architecture](../README.md#architecture) (data flows per mode).
2. [architecture.md](architecture.md) — one page: purpose, quality goals, context and containers, key concepts, risks (explanation).
3. [../PROTOCOL.md](../PROTOCOL.md) — the WebSocket protocol between server and bridge, every frame and stream event (reference).
4. [file-transfers.md](file-transfers.md) — streamed uploads and downloads to and from the machine (guide).
5. [decisions/README.md](decisions/README.md) — decision records; accepted ones are binding (reference).
6. [../CHANGELOG.md](../CHANGELOG.md) — releases from 0.16.0-RC1 on; earlier ones are on the GitHub releases page.
7. [../tests/Browser/README.md](../tests/Browser/README.md) — manual Playwright tests for the chat bundle (guide).
8. [../AGENTS.md](../AGENTS.md) — commands, non-negotiables and the load-bearing decisions, for agents.
