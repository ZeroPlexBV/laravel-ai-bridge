<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\MessageTypes;

/**
 * A conversation's "a turn is running here" bookmark (`streaming_request_id`), and the
 * endings that used to leave it behind.
 *
 * The recorder clears it when a turn ends through a StreamHandler that knows the turn. Three
 * endings never reach one, and each left the bookmark naming a turn that was over:
 *
 *  - **The serve process stopped mid-turn** (a deploy, a restart). The turn's handler died
 *    with it, so nothing relayed the rest of the turn and nothing ended it.
 *    {@see failAllRunning()} ends such turns on the way down.
 *  - **The bridge ends a turn the (new) serve process never knew**: the bridge reconnects
 *    to the restarted process and sends `done`/`error`/`cancelled` for a request nobody here
 *    is waiting on. {@see settleOrphan()} ends it for the owner's bridge.
 *  - **Everything else** (a process killed outright, a store that was down at the moment of
 *    the write): {@see sweep()}, on a schedule, clears every bookmark whose turn is over.
 *
 * Every clear is compare-and-set on the id it clears, so a bookmark that has already moved on
 * to the next turn (or an application's claim for one) is never touched.
 */
final class TurnMarker
{
    /** The error code a turn gets when the server lost it. */
    public const LOST_CODE = 'relay_lost';

    /**
     * Clear the bookmark if it still names this turn.
     *
     * @return int how many conversations were cleared (0 or 1 in practice)
     */
    public static function clear(string $requestId): int
    {
        if ($requestId === '') {
            return 0;
        }

        try {
            return Conversation::query()
                ->where('streaming_request_id', $requestId)
                ->update(['streaming_request_id' => null]);
        } catch (\Throwable $e) {
            Log::warning('AI Bridge: failed to clear streaming_request_id', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * A bridge ended a turn that no handler in this process knows (it was started by a serve
     * process that has since stopped). Only the bridge of the user the conversation is routed
     * to may end it: the conversation's connection key must be the sender's user id.
     *
     * A buffer still streaming is ended as failed with {@see LOST_CODE}: whatever the machine
     * wrote while nobody here was listening is gone, and a reader waiting on the buffer would
     * otherwise wait for its whole lifetime. The bookmark is cleared.
     *
     * @return bool whether a turn was settled
     */
    public static function settleOrphan(string $requestId, ?string $senderUserId): bool
    {
        if ($requestId === '' || $senderUserId === null || $senderUserId === '') {
            return false;
        }

        try {
            $conversation = Conversation::query()
                ->with('connection')
                ->where('streaming_request_id', $requestId)
                ->first();
        } catch (\Throwable) {
            return false;
        }

        if ($conversation === null) {
            return false;
        }

        $key = $conversation->connection?->connection_key;
        if ($key === null || $key === '' || (string) $key !== $senderUserId) {
            Log::info('AI Bridge: an ending for another user\'s turn was not applied', [
                'request_id' => $requestId,
                'conversation_id' => $conversation->id,
            ]);

            return false;
        }

        self::failIfStreaming($requestId);
        self::clear($requestId);

        Log::info('AI Bridge: ended a turn this process had lost', [
            'request_id' => $requestId,
            'conversation_id' => $conversation->id,
        ]);

        return true;
    }

    /**
     * End the buffer of a turn nobody will relay any more, when it still says streaming.
     */
    public static function failIfStreaming(string $requestId, string $message = 'The server lost this reply while it was running (it restarted). Part of it did not arrive; ask again to continue.'): void
    {
        try {
            $store = app(StreamStoreContract::class);
            if (($store->status($requestId)['status'] ?? null) !== 'streaming') {
                return;
            }
            $store->appendEvent($requestId, MessageTypes::ERROR, ['code' => self::LOST_CODE, 'message' => $message]);
            $store->complete($requestId, 'failed');
        } catch (\Throwable $e) {
            Log::warning('AI Bridge: could not end the buffer of a lost turn', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear every bookmark whose turn is over.
     *
     * Over means the turn's buffer has ended (completed, failed, cancelled), or has no buffer
     * at all (`not_found`: expired, or never written) and the bookmark is older than the grace
     * period. The grace period leaves alone an application's claim for a turn that is still
     * being started (a bookmark set before the buffer exists). A buffer still `streaming` is
     * left alone: it ends by itself (its lifetime runs out, and it is then `not_found`).
     *
     * @return list<string> the request ids cleared
     */
    public static function sweep(int $graceSeconds = 600): array
    {
        $cleared = [];
        $store = app(StreamStoreContract::class);
        $before = now()->subSeconds(max(0, $graceSeconds));

        Conversation::query()
            ->whereNotNull('streaming_request_id')
            ->orderBy('id')
            ->select(['id', 'streaming_request_id', 'updated_at'])
            ->chunkById(200, function ($conversations) use ($store, $before, &$cleared): void {
                foreach ($conversations as $conversation) {
                    $requestId = (string) $conversation->streaming_request_id;

                    try {
                        $status = (string) ($store->status($requestId)['status'] ?? 'not_found');
                    } catch (\Throwable) {
                        // A store that cannot answer says nothing about the turn.
                        continue;
                    }

                    $over = in_array($status, ['completed', 'failed', 'cancelled'], true)
                        || ($status === 'not_found' && $conversation->updated_at !== null && $conversation->updated_at->lt($before));

                    if (! $over) {
                        continue;
                    }

                    $done = Conversation::query()
                        ->whereKey($conversation->id)
                        ->where('streaming_request_id', $requestId)
                        ->update(['streaming_request_id' => null]);

                    if ($done === 1) {
                        $cleared[] = $requestId;
                    }
                }
            });

        return $cleared;
    }
}
