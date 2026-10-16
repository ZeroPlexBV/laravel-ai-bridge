<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A bridge reported its upstream MCP servers and the list differs from what it said before
 * on this connection (`upstream_status`; the first report after connecting always counts).
 *
 * `$upstreams` is the complete list, each `{server, transport, origin, auth, state, tools,
 * error}` as PROTOCOL.md describes. Fired in the serve process.
 */
class UpstreamStatusChanged
{
    use Dispatchable;

    /**
     * @param  list<array<string, mixed>>  $upstreams
     */
    public function __construct(
        public readonly string $connectionKey,
        public readonly ?int $connectionId,
        public readonly array $upstreams,
    ) {}
}
