<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Upstreams;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Sign-ins to upstream MCP servers that a bridge asked for and the person has not finished.
 *
 * Written by the serve process when a bridge sends `upstream_auth_required`; read by the web
 * process when the authorization server sends the person's browser back to the callback.
 * The two are different processes, so this lives in the cache (`ai-bridge.upstream_oauth.cache_store`,
 * default the application's default store), which must therefore be one both can reach:
 * not `array`, and not `file` on two hosts.
 *
 * Two keys per sign-in: the state (hashed; it is a secret) => the record, for the callback;
 * and per connection a map of server => record, for "which sign-ins are waiting". At most
 * one sign-in is pending per upstream, so a new one for the same server replaces the old.
 *
 * A record: `state`, `connection_key` (the bridge's identity in the serve process),
 * `connection_id` (the ai_bridge_connections id, or null), `server`, `authorization_url`,
 * `expires_at` (ISO 8601, UTC) and `reason`.
 */
class PendingSignIns
{
    /** The longest a sign-in is kept, whatever `expires_at` the bridge sent (seconds). */
    public const MAX_TTL = 900;

    private const PREFIX = 'ai-bridge:upstream-oauth:';

    /**
     * Keep a sign-in until it expires, replacing any earlier one for the same upstream.
     *
     * @param  array{state: string, connection_key: string, connection_id: int|null, server: string, authorization_url: string, expires_at: string, reason: string}  $record
     */
    public function put(array $record): void
    {
        $ttl = $this->ttlOf($record['expires_at']);
        if ($ttl <= 0) {
            return;
        }

        $index = $this->forConnectionKey($record['connection_key']);
        $previous = $index[$record['server']] ?? null;
        if ($previous !== null && $previous['state'] !== $record['state']) {
            $this->store()->forget($this->stateKey($previous['state']));
        }

        $this->store()->put($this->stateKey($record['state']), $record, $ttl);

        $index[$record['server']] = $record;
        $this->store()->put($this->indexKey($record['connection_key']), $index, self::MAX_TTL);
    }

    /**
     * The sign-in a state names, expired or not (the caller tells them apart), or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $state): ?array
    {
        if ($state === '' || strlen($state) > 512) {
            return null;
        }

        $record = $this->store()->get($this->stateKey($state));

        return is_array($record) && ($record['state'] ?? null) === $state ? $record : null;
    }

    /** Forget one sign-in by its state (used, expired or superseded). */
    public function forget(string $state): void
    {
        $record = $this->find($state);
        $this->store()->forget($this->stateKey($state));

        if ($record !== null) {
            $index = $this->forConnectionKey((string) $record['connection_key']);
            if (($index[$record['server']]['state'] ?? null) === $state) {
                unset($index[$record['server']]);
                $this->putIndex((string) $record['connection_key'], $index);
            }
        }
    }

    /** Forget the sign-in pending for one upstream of a connection, if any. */
    public function forgetServer(string $connectionKey, string $server): void
    {
        $index = $this->forConnectionKey($connectionKey);
        if (isset($index[$server])) {
            $this->store()->forget($this->stateKey((string) $index[$server]['state']));
            unset($index[$server]);
            $this->putIndex($connectionKey, $index);
        }
    }

    /**
     * The unexpired sign-ins waiting on a connection, by server.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forConnectionKey(string $connectionKey): array
    {
        $index = $this->store()->get($this->indexKey($connectionKey));
        if (! is_array($index)) {
            return [];
        }

        return array_filter($index, fn ($r): bool => is_array($r) && $this->ttlOf((string) ($r['expires_at'] ?? '')) > 0);
    }

    /** Whether a record's `expires_at` has passed. */
    public function isExpired(array $record): bool
    {
        return $this->ttlOf((string) ($record['expires_at'] ?? '')) <= 0;
    }

    /**
     * Seconds until `expires_at`, at most MAX_TTL; 0 when past or unreadable.
     */
    private function ttlOf(string $expiresAt): int
    {
        $at = strtotime($expiresAt);

        return $at === false ? 0 : max(0, min(self::MAX_TTL, $at - time()));
    }

    /** @param  array<string, array<string, mixed>>  $index */
    private function putIndex(string $connectionKey, array $index): void
    {
        if ($index === []) {
            $this->store()->forget($this->indexKey($connectionKey));
        } else {
            $this->store()->put($this->indexKey($connectionKey), $index, self::MAX_TTL);
        }
    }

    private function stateKey(string $state): string
    {
        return self::PREFIX.'state:'.hash('sha256', $state);
    }

    private function indexKey(string $connectionKey): string
    {
        return self::PREFIX.'connection:'.hash('sha256', $connectionKey);
    }

    private function store(): Repository
    {
        $store = config('ai-bridge.upstream_oauth.cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
