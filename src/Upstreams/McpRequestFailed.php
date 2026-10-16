<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Upstreams;

use RuntimeException;

/**
 * A view's request to an upstream MCP server that did not produce a result.
 *
 * `$errorCode` is the bridge's `mcp_result` code (`unknown_server`, `unavailable`, `timeout`,
 * `refused`, `unsupported`, `upstream_error`) or one decided before it reached a bridge:
 * `invalid_request` (not a bridge connection, a malformed request), `not_connected`,
 * `mcp_apps_unsupported` (the bridge's hello had no `mcp_apps`), `unreachable` (the serve
 * process could not be reached). The message is fit to show a person; it never carries a
 * secret (the bridge redacts the upstream's).
 */
class McpRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
