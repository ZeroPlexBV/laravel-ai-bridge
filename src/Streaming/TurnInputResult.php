<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

/**
 * Where a message sent into a running turn landed.
 *
 * Returned by {@see \Tetrix\AiBridge\AiBridgeManager::sendTurnInput()}.
 *
 * `accepted` means the bridge wrote the message to the CLI's input. It does NOT mean the
 * assistant has read it: a `user_input` stream event carrying the same message id says when
 * it did, and nothing promises the assistant changes course because of it.
 *
 * A rejection's `reason`:
 *  - `turn_not_running` — no turn is running under that id any more. The message was not
 *    delivered, and the one thing to do with it is start a new turn.
 *  - `input_not_open` — the turn runs, but was not started with its input open.
 *  - `no_answer` — the bridge did not answer in time, or went away. It may have taken the
 *    message; do not resend it as a new turn.
 *  - `not_owner` — the turn belongs to another user.
 *  - `duplicate` — the same message id is still waiting on its answer.
 *  - `send_failed` / `unreachable` — the frame could not be put to the bridge, or the serve
 *    process could not be reached at all.
 *  - null — the bridge refused without a reason this package knows.
 *
 * Any reason other than `turn_not_running` is best treated as "hold the message until the
 * turn ends", which is what a chat did before turn input existed.
 */
final class TurnInputResult
{
    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
    ) {}

    public static function accepted(): self
    {
        return new self(self::ACCEPTED);
    }

    public static function rejected(?string $reason): self
    {
        return new self(self::REJECTED, $reason);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    /** @return array{status: string, reason?: string} */
    public function toArray(): array
    {
        return $this->reason === null
            ? ['status' => $this->status]
            : ['status' => $this->status, 'reason' => $this->reason];
    }
}
