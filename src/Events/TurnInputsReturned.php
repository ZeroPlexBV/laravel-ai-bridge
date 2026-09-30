<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A bridge named the mid-turn messages its CLI never read, for a turn this server had
 * already ended.
 *
 * Happens after a dropped connection: the server fails the turn the moment the socket
 * closes (`bridge_disconnected`), before anyone knows which delivered messages the CLI had
 * taken in. When the bridge reconnects it replays its own ending with that list. Every
 * accepted message the list names was NOT read; one it does not name WAS read, and its
 * answer went with the connection.
 *
 * Fired in the serve process. The same list is written to the turn's stream metadata as
 * `pending_inputs`, for a process that polls instead of listening.
 */
class TurnInputsReturned
{
    use Dispatchable;

    public function __construct(
        /** The user whose bridge ran the turn (the connection key for a managed connection). */
        public readonly int|string $userId,
        /** The turn's request id. */
        public readonly string $requestId,
        /** @var list<string> The application's message ids, oldest first. */
        public readonly array $pendingInputs,
    ) {}
}
