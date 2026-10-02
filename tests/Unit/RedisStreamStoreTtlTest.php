<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Tetrix\AiBridge\Streaming\Drivers\RedisStreamStore;

/*
|--------------------------------------------------------------------------
| RedisStreamStore lifetimes
|--------------------------------------------------------------------------
|
| How long a turn's keys live, against an in-memory Redis with a clock we
| move by hand. The rest of the driver is exercised in the dev stack (see
| StreamStoreTest); lifetimes are not, because nobody waits an hour there.
|
| A long turn (a helper working for over an hour) lost its status and
| metadata exactly ttl_streaming after it started: only the event log was
| renewed. It then read as not_found while still writing events, and
| inputOpen() refused every message typed for it.
|
*/

/** Just enough of Redis for the store: strings, lists and expiry, on a manual clock. */
final class ClockedRedis
{
    public int $now = 0;

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, int> */
    private array $expiresAt = [];

    private function live(string $key): bool
    {
        if (isset($this->expiresAt[$key]) && $this->expiresAt[$key] <= $this->now) {
            unset($this->values[$key], $this->expiresAt[$key]);
        }

        return array_key_exists($key, $this->values);
    }

    public function get(string $key): mixed
    {
        return $this->live($key) && is_string($this->values[$key]) ? $this->values[$key] : null;
    }

    public function set(string $key, string $value, ?string $ex = null, ?int $ttl = null): bool
    {
        $this->values[$key] = $value;
        unset($this->expiresAt[$key]);
        if ($ex === 'EX' && $ttl !== null) {
            $this->expiresAt[$key] = $this->now + $ttl;
        }

        return true;
    }

    public function setnx(string $key, string $value): int
    {
        if ($this->live($key)) {
            return 0;
        }
        $this->values[$key] = $value;

        return 1;
    }

    public function expire(string $key, int $ttl): int
    {
        if (! $this->live($key)) {
            return 0;
        }
        $this->expiresAt[$key] = $this->now + $ttl;

        return 1;
    }

    public function rpush(string $key, string $value): int
    {
        if (! $this->live($key)) {
            $this->values[$key] = [];
        }
        $this->values[$key][] = $value;

        return count($this->values[$key]);
    }

    public function llen(string $key): int
    {
        return $this->live($key) ? count($this->values[$key]) : 0;
    }

    public function lrange(string $key, int $start, int $stop): array
    {
        if (! $this->live($key)) {
            return [];
        }

        return array_slice($this->values[$key], $start, $stop < 0 ? null : $stop - $start + 1);
    }

    public function exists(string $key): int
    {
        return $this->live($key) ? 1 : 0;
    }

    public function del(string ...$keys): int
    {
        $n = 0;
        foreach ($keys as $key) {
            if ($this->live($key)) {
                unset($this->values[$key], $this->expiresAt[$key]);
                $n++;
            }
        }

        return $n;
    }
}

function clocked_store(ClockedRedis $redis): RedisStreamStore
{
    $connection = new class($redis) extends Connection
    {
        public function __construct(ClockedRedis $client)
        {
            $this->client = $client;
        }

        public function createSubscription($channels, Closure $callback, $method = 'subscribe')
        {
        }
    };

    $factory = new class($connection) implements RedisFactory
    {
        public function __construct(private Connection $connection) {}

        public function connection($name = null)
        {
            return $this->connection;
        }
    };

    return new RedisStreamStore($factory, null, 'ai-bridge:stream', 3600, 1800);
}

test('a turn that keeps writing events keeps its status and metadata past ttl_streaming', function () {
    $redis = new ClockedRedis;
    $store = clocked_store($redis);

    $store->start('req-long', ['conversation_id' => 'c1']);
    $store->mergeMetadata('req-long', ['input_open' => true]);

    // A helper reports every so often, for well over the hour.
    for ($minute = 10; $minute <= 90; $minute += 10) {
        $redis->now = $minute * 60;
        $store->appendEvent('req-long', 'task', ['phase' => 'progress']);
    }

    $redis->now = 95 * 60;
    $status = $store->status('req-long');

    // MUTATION: drop the renewal in appendEvent() and this reads not_found.
    expect($status['status'])->toBe('streaming')
        ->and($status['metadata']['input_open'] ?? null)->toBeTrue()
        ->and($status['event_count'])->toBe(9);
});

test('a turn that goes quiet still expires ttl_streaming after its last event', function () {
    $redis = new ClockedRedis;
    $store = clocked_store($redis);

    $store->start('req-quiet', []);
    $redis->now = 600;
    $store->appendEvent('req-quiet', 'block_delta', []);

    $redis->now = 600 + 3600;

    expect($store->status('req-quiet')['status'])->toBe('not_found');
});

test('an event after the end does not stretch a finished turn', function () {
    $redis = new ClockedRedis;
    $store = clocked_store($redis);

    $store->start('req-done', ['input_open' => true]);
    $store->complete('req-done', 'completed');
    $store->appendEvent('req-done', 'late', []);

    $redis->now = 1800;

    expect($store->status('req-done')['status'])->toBe('not_found');
});
