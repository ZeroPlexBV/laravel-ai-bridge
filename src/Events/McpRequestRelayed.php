<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A view's request to an upstream MCP server was relayed through a bridge, or refused.
 *
 * One per AiBridge::mcpRequest() call, for a host to audit what views did on a person's
 * behalf. `$target` is the tool name for `tools/call` and the uri for `resources/read`.
 * `$outcome` is `ok`, or the error code the call threw: the bridge's (`unknown_server`,
 * `unavailable`, `timeout`, `refused`, `unsupported`, `upstream_error`) or one decided here
 * (`invalid_request`, `not_connected`, `mcp_apps_unsupported`, `unreachable`). Fired in the
 * process that called mcpRequest(). Neither the arguments nor the result are carried.
 */
class McpRequestRelayed
{
    use Dispatchable;

    public function __construct(
        public readonly int|string|null $userId,
        public readonly int $connectionId,
        public readonly string $server,
        public readonly string $method,
        public readonly ?string $target,
        public readonly string $outcome,
        public readonly int $durationMs,
    ) {}
}
