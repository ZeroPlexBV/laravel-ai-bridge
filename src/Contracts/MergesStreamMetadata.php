<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Contracts;

/**
 * A stream store that can add to a turn's metadata after start().
 *
 * Separate from {@see StreamStoreContract} on purpose: adding a method to that
 * interface would break every driver an application registered through
 * `StreamStore::extend()` on a minor release. Both bundled drivers implement
 * this; a custom driver that does not simply never reports what is written
 * through it (so, for example, {@see \Tetrix\AiBridge\AiBridgeManager::inputOpen()}
 * answers false and a mid-turn message is held, exactly as before).
 *
 * Written by the serve process when the bridge tells it something about a
 * running turn that another process has to read — `input_open` from the
 * `ai_request_ack` being the first such fact.
 */
interface MergesStreamMetadata
{
    /**
     * Merge keys into a started turn's metadata, keeping the rest.
     *
     * A no-op for a turn that has no entry (never started, or already expired):
     * metadata for a turn nobody can read would only linger.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function mergeMetadata(string $requestId, array $metadata): void;
}
