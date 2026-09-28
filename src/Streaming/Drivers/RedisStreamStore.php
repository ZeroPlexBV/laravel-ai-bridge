<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming\Drivers;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Tetrix\AiBridge\Contracts\MergesStreamMetadata;
use Tetrix\AiBridge\Contracts\StreamStoreContract;

/**
 * Redis-backed stream store.
 *
 * Keys (under the configured prefix):
 *   {prefix}:{rid}:events    — Redis list of JSON-encoded {index,event,data}
 *   {prefix}:{rid}:meta      — JSON metadata (conversation_id, started_at, ...)
 *   {prefix}:{rid}:status    — string: streaming|completed|failed|cancelled
 *   {prefix}:{rid}:abort     — exists ⇒ aborted
 *
 * TTLs:
 *   While status=streaming, each touch resets TTL to $streamingTtl.
 *   On complete(), TTL is shortened to $completedTtl so a recent reload can
 *   still replay but the entry doesn't linger.
 */
final class RedisStreamStore implements StreamStoreContract, MergesStreamMetadata
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly ?string $connectionName,
        private readonly string $prefix,
        private readonly int $streamingTtl,
        private readonly int $completedTtl,
    ) {}

    public function start(string $requestId, array $metadata = []): void
    {
        $conn = $this->conn();

        // SETNX on status — first writer wins, keeps the existing log if
        // start() is called twice for the same request_id.
        $created = (bool) $conn->setnx($this->key($requestId, 'status'), 'streaming');
        if ($created) {
            $conn->expire($this->key($requestId, 'status'), $this->streamingTtl);
        }

        $conn->set(
            $this->key($requestId, 'meta'),
            json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'EX',
            $this->streamingTtl,
        );
    }

    public function mergeMetadata(string $requestId, array $metadata): void
    {
        $conn = $this->conn();

        // Only for a turn that exists: status is the key every reader checks
        // first, so metadata without it would be written for nobody.
        if ($conn->get($this->key($requestId, 'status')) === null) {
            return;
        }

        // Read-modify-write. Every writer after start() is the one serve
        // process, whose event loop runs one frame at a time, so there is no
        // second writer to race; start() itself runs before the turn is sent.
        $rawMeta = $conn->get($this->key($requestId, 'meta'));
        $current = $rawMeta === null ? [] : json_decode((string) $rawMeta, true);

        $conn->set(
            $this->key($requestId, 'meta'),
            json_encode(array_merge(is_array($current) ? $current : [], $metadata), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'EX',
            $this->streamingTtl,
        );
    }

    public function appendEvent(string $requestId, string $eventName, array $data): int
    {
        $conn = $this->conn();
        $eventsKey = $this->key($requestId, 'events');

        // RPUSH returns the new list length; the appended element's index is
        // length - 1. The index is intentionally NOT encoded into the payload:
        // computing it from list position on read avoids a RPUSH-then-LSET
        // race where a concurrent LRANGE could otherwise observe a transient
        // null placeholder. range() assigns index = start + offset on read.
        $newLength = (int) $conn->rpush(
            $eventsKey,
            json_encode([
                'event' => $eventName,
                'data' => $data,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $conn->expire($eventsKey, $this->streamingTtl);

        return $newLength - 1;
    }

    public function range(string $requestId, int $fromIndex = -1): array
    {
        $conn = $this->conn();
        $eventsKey = $this->key($requestId, 'events');

        $start = $fromIndex < 0 ? 0 : $fromIndex + 1;
        $raw = $conn->lrange($eventsKey, $start, -1);

        // Index is the element's position in the list — computed here rather
        // than read from the payload so that no race window can ever surface
        // a stale or placeholder index to a concurrent reader.
        $out = [];
        foreach ($raw as $offset => $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded) && isset($decoded['event'])) {
                $out[] = [
                    'index' => $start + $offset,
                    'event' => (string) $decoded['event'],
                    'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
                ];
            }
        }

        return $out;
    }

    public function status(string $requestId): array
    {
        $conn = $this->conn();

        $status = $conn->get($this->key($requestId, 'status'));
        if ($status === null) {
            return [
                'status' => 'not_found',
                'event_count' => 0,
                'last_event_index' => -1,
                'metadata' => [],
            ];
        }

        $count = (int) $conn->llen($this->key($requestId, 'events'));
        $rawMeta = $conn->get($this->key($requestId, 'meta'));
        $meta = $rawMeta === null ? [] : (json_decode((string) $rawMeta, true) ?: []);

        return [
            'status' => (string) $status,
            'event_count' => $count,
            'last_event_index' => $count === 0 ? -1 : $count - 1,
            'metadata' => is_array($meta) ? $meta : [],
        ];
    }

    public function setAbort(string $requestId): void
    {
        $conn = $this->conn();
        $conn->set($this->key($requestId, 'abort'), '1', 'EX', $this->streamingTtl);
    }

    public function isAborted(string $requestId): bool
    {
        return (bool) $this->conn()->exists($this->key($requestId, 'abort'));
    }

    public function complete(string $requestId, string $status): void
    {
        $conn = $this->conn();
        $conn->set($this->key($requestId, 'status'), $status, 'EX', $this->completedTtl);
        $conn->expire($this->key($requestId, 'events'), $this->completedTtl);
        $conn->expire($this->key($requestId, 'meta'), $this->completedTtl);

        // The stop belongs to the turn that was stopped, and that turn is over.
        //
        // Only cleanup() removed this key, and nothing calls cleanup() — so a
        // reused request_id (the relay path takes one from its caller) began
        // life already aborted, and died at its first stream event. Now that the
        // heartbeat polls too, it would die before producing a single token,
        // which reads as a turn that refused to run.
        //
        // Here rather than in start(), for two reasons. start() cannot tell a
        // reused id from a retried start: `complete()` rewrites the status key,
        // so the SETNX that would have gated it reports "already exists" for
        // exactly the reuse this is meant to catch. And clearing on start would
        // discard an abort that arrived BEFORE the turn started, which the
        // contract explicitly allows for a caller racing its own request.
        $conn->del($this->key($requestId, 'abort'));
    }

    public function cleanup(string $requestId): void
    {
        $conn = $this->conn();
        $conn->del(
            $this->key($requestId, 'events'),
            $this->key($requestId, 'meta'),
            $this->key($requestId, 'status'),
            $this->key($requestId, 'abort'),
        );
    }

    private function conn(): Connection
    {
        return $this->redis->connection($this->connectionName);
    }

    private function key(string $requestId, string $suffix): string
    {
        return $this->prefix.':'.$requestId.':'.$suffix;
    }
}
