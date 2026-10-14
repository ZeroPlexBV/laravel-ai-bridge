<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Facades;

use Illuminate\Support\Facades\Facade;
use Tetrix\AiBridge\Streaming\StreamStoreManager;

/**
 * Facade for the per-turn streaming event buffer.
 *
 * Resolves to the {@see StreamStoreManager}, which forwards driver methods
 * (start / appendEvent / range / status / setAbort / isAborted / complete /
 * cleanup) to the default driver via Laravel's Manager `__call`. Apps that
 * need a non-default driver call `StreamStore::driver('foo')`; apps that
 * want to register their own driver call `StreamStore::extend('foo', …)`.
 *
 * @method static void start(string $requestId, array $metadata = [])
 * @method static int appendEvent(string $requestId, string $eventName, array $data)
 * @method static array range(string $requestId, int $fromIndex = -1)
 * @method static array status(string $requestId)
 * @method static void setAbort(string $requestId)
 * @method static bool isAborted(string $requestId)
 * @method static void complete(string $requestId, string $status)
 * @method static void cleanup(string $requestId)
 * @method static void putValue(string $requestId, string $key, array $value)
 * @method static array|null getValue(string $requestId, string $key)
 * @method static \Tetrix\AiBridge\Contracts\StreamStoreContract driver(?string $driver = null)
 * @method static \Tetrix\AiBridge\Streaming\StreamStoreManager extend(string $driver, \Closure $callback)
 *
 * @see \Tetrix\AiBridge\Streaming\StreamStoreManager
 */
class StreamStore extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StreamStoreManager::class;
    }
}
