<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sign-in to an upstream MCP server ended, as the callback route saw it.
 *
 * Fired in the web process once the code was passed to the bridge: with `$ok` true when the
 * bridge reported the tokens obtained, false otherwise with `$code`: one of the bridge's
 * (`unknown_state`, `denied`, `exchange_failed`, `issuer_mismatch`, ...) or `timeout` (no
 * answer within ai-bridge.upstream_oauth.callback_timeout; the sign-in may still finish) or
 * `not_connected` / `unreachable` (the bridge or the serve process could not be reached).
 * Not fired for a callback refused before anything was forwarded (unknown state, someone
 * else's sign-in).
 *
 * Also fired in the serve process, with `$userId` null, for a failure the bridge reports
 * without a state (`upstream_auth_result` with `state: null`): a sign-in it could not
 * prepare (`discovery_failed`, `registration_failed`, `no_redirect_uri`, ...), whether it
 * tried on its own after connecting or was asked with startSignIn().
 */
class UpstreamAuthCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly ?int $connectionId,
        public readonly string $connectionKey,
        /** The signed-in user who finished it in their browser; null when fired by the serve process. */
        public readonly int|string|null $userId,
        public readonly string $server,
        public readonly bool $ok,
        public readonly ?string $code = null,
        /** The bridge's message, for a person; never contains a token. */
        public readonly ?string $error = null,
    ) {}
}
