<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Contracts;

/**
 * A stream store that can keep small keyed values beside a turn's event log.
 *
 * Separate from {@see StreamStoreContract} for the same reason as
 * {@see MergesStreamMetadata}: adding a method there would break every driver an
 * application registered through `StreamStore::extend()`. Both bundled drivers
 * implement it; on a custom driver that does not, nothing is kept, so a tool's
 * view is simply not shown.
 *
 * Written by the serve process when it executes a tool with a view: the full
 * result, keyed by the CLI's own call id, so the stream's `tool_result` for that
 * call can carry it as `ui`. A value lives as long as the turn does.
 */
interface StoresStreamValues
{
    /**
     * Keep a value for a turn under a key, replacing any earlier one.
     *
     * @param  array<string, mixed>  $value
     */
    public function putValue(string $requestId, string $key, array $value): void;

    /**
     * A value kept for a turn, or null when there is none (or it expired).
     *
     * @return array<string, mixed>|null
     */
    public function getValue(string $requestId, string $key): ?array;
}
