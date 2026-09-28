<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming\Drivers;

use Tetrix\AiBridge\Contracts\MergesStreamMetadata;
use Tetrix\AiBridge\Contracts\StreamStoreContract;

/**
 * In-memory stream store — for tests and a sane fallback when nothing else is configured.
 *
 * Not suitable for production: state lives in the PHP process only, so the
 * web process cannot see what the serve process wrote (and vice versa).
 */
final class ArrayStreamStore implements StreamStoreContract, MergesStreamMetadata
{
    /** @var array<string, array{status: string, metadata: array<string, mixed>, events: list<array{index: int, event: string, data: array<string, mixed>}>, aborted: bool}> */
    private array $turns = [];

    public function start(string $requestId, array $metadata = []): void
    {
        if (isset($this->turns[$requestId])) {
            return;
        }

        $this->turns[$requestId] = [
            'status' => 'streaming',
            'metadata' => $metadata,
            'events' => [],
            'aborted' => false,
        ];
    }

    public function mergeMetadata(string $requestId, array $metadata): void
    {
        if (! isset($this->turns[$requestId])) {
            return;
        }

        $this->turns[$requestId]['metadata'] = array_merge($this->turns[$requestId]['metadata'], $metadata);
    }

    public function appendEvent(string $requestId, string $eventName, array $data): int
    {
        // Auto-create on first append so callers don't have to remember to
        // start() — matches the Redis driver's behaviour and keeps tests
        // terse. Status starts as "streaming".
        if (! isset($this->turns[$requestId])) {
            $this->start($requestId);
        }

        $index = count($this->turns[$requestId]['events']);
        $this->turns[$requestId]['events'][] = [
            'index' => $index,
            'event' => $eventName,
            'data' => $data,
        ];

        return $index;
    }

    public function range(string $requestId, int $fromIndex = -1): array
    {
        if (! isset($this->turns[$requestId])) {
            return [];
        }

        if ($fromIndex < 0) {
            return $this->turns[$requestId]['events'];
        }

        return array_values(array_filter(
            $this->turns[$requestId]['events'],
            static fn (array $e): bool => $e['index'] > $fromIndex,
        ));
    }

    public function status(string $requestId): array
    {
        if (! isset($this->turns[$requestId])) {
            return [
                'status' => 'not_found',
                'event_count' => 0,
                'last_event_index' => -1,
                'metadata' => [],
            ];
        }

        $turn = $this->turns[$requestId];
        $count = count($turn['events']);

        return [
            'status' => $turn['status'],
            'event_count' => $count,
            'last_event_index' => $count === 0 ? -1 : $count - 1,
            'metadata' => $turn['metadata'],
        ];
    }

    public function setAbort(string $requestId): void
    {
        if (! isset($this->turns[$requestId])) {
            // Allow setting abort before start() so a race-aborting caller
            // doesn't have to know whether the buffer entry exists yet.
            $this->turns[$requestId] = [
                'status' => 'streaming',
                'metadata' => [],
                'events' => [],
                'aborted' => false,
            ];
        }
        $this->turns[$requestId]['aborted'] = true;
    }

    public function isAborted(string $requestId): bool
    {
        return $this->turns[$requestId]['aborted'] ?? false;
    }

    public function complete(string $requestId, string $status): void
    {
        if (! isset($this->turns[$requestId])) {
            return;
        }
        $this->turns[$requestId]['status'] = $status;
        // The stop belongs to the turn that was stopped, and that turn is over.
        // See the Redis driver, where a request_id outliving its turn is what
        // makes this matter.
        $this->turns[$requestId]['aborted'] = false;
    }

    public function cleanup(string $requestId): void
    {
        unset($this->turns[$requestId]);
    }

    /**
     * Drop every stored turn. Test helper — not part of the contract.
     */
    public function flush(): void
    {
        $this->turns = [];
    }
}
