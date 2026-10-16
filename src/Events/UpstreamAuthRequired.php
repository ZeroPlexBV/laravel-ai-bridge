<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A bridge needs the person to sign in to one of its upstream MCP servers (`upstream_auth_required`).
 *
 * Show a "Connect <server>" card or notice that opens `$authorizationUrl` in the person's
 * browser; the authorization server sends them back to the package's callback route, which
 * passes the code to the bridge. The sign-in is also kept until `$expiresAt`, so a page that
 * polls instead of listening finds it through AiBridge::upstreams()->pending().
 *
 * Fired in the serve process (where bridge frames arrive), so a listener that has to reach a
 * browser broadcasts or stores; it has no request and no signed-in user.
 */
class UpstreamAuthRequired
{
    use Dispatchable;

    public function __construct(
        /** The bridge's identity in the serve process (the connection key for a managed connection). */
        public readonly string $connectionKey,
        /** The ai_bridge_connections id the bridge connected as, or null when it has no row. */
        public readonly ?int $connectionId,
        /** The upstream's name in the bridge's `--mcp-upstreams` file. */
        public readonly string $server,
        /** Where to send the person's browser. Contains the state; treat it as a one-time secret. */
        public readonly string $authorizationUrl,
        /** When the sign-in stops working, ISO 8601 UTC. */
        public readonly string $expiresAt,
        /** `no_token`, `refresh_failed`, `rejected` or `requested`. */
        public readonly string $reason,
    ) {}
}
