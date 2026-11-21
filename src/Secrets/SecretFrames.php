<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Secrets;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/**
 * The bridge's `secret_result` frames, matched to the request waiting for them.
 *
 * Runs in the serve process's event loop, so every field is type-checked: a TypeError here
 * would drop every connected bridge. A result never carries a secret's value, and nothing
 * the bridge sends is passed on unchecked: names and descriptions are cut to length, a code
 * outside {@see self::CODES} becomes `failed`.
 */
class SecretFrames
{
    /** What a failed secret request may answer; anything else becomes `failed`. */
    public const CODES = ['no_secret_tool', 'not_found', 'invalid_name', 'invalid_request', 'exists', 'failed'];

    /** The most secrets one list answer is read for. */
    public const MAX_SECRETS = 500;

    public function __construct(
        private readonly BridgeConnectionManager $connections,
    ) {}

    /** @param  array<string, mixed>  $message */
    public function result(string $connectionId, array $message): ?array
    {
        $userId = $this->connections->getUserIdByConnectionId($connectionId);
        $id = $message['id'] ?? null;

        if ($userId === null) {
            Log::warning('AI Bridge: secret_result from unauthenticated connection', ['connection_id' => $connectionId]);

            return null;
        }

        if (! is_string($id) || $id === '' || strlen($id) > 128) {
            return null;
        }

        $this->connections->resolvePendingExchange(self::exchangeKey($userId, $id), $userId, self::answerOf($message));

        return null;
    }

    /** The waiter's key: scoped by user, so one machine can never answer another's request. */
    public static function exchangeKey(string $userId, string $id): string
    {
        return 'secret:'.$userId.':'.$id;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public static function answerOf(array $message): array
    {
        if (($message['ok'] ?? null) !== true) {
            $code = $message['code'] ?? null;
            $error = $message['error'] ?? null;

            return [
                'ok' => false,
                'code' => is_string($code) && in_array($code, self::CODES, true) ? $code : 'failed',
                'error' => is_string($error) && $error !== '' ? mb_substr($error, 0, 500) : 'The machine could not do this.',
            ];
        }

        $answer = ['ok' => true];
        if (array_key_exists('secrets', $message)) {
            $answer['secrets'] = self::secretsOf($message['secrets']);
        }

        return $answer;
    }

    /**
     * A list answer reduced to `{name, description, updated_at}` rows. Anything else the
     * bridge put in a row is dropped, so a value could not slip through even by mistake.
     *
     * @return list<array{name: string, description: string|null, updated_at: string|null}>
     */
    public static function secretsOf(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }

        $secrets = [];
        foreach (array_slice($rows, 0, self::MAX_SECRETS) as $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            if (! is_string($name) || ! SecretName::valid($name)) {
                continue;
            }

            $description = $row['description'] ?? null;
            $updated = $row['updated_at'] ?? null;
            $secrets[] = [
                'name' => $name,
                'description' => is_string($description) && $description !== '' ? mb_substr($description, 0, SecretName::MAX_DESCRIPTION) : null,
                'updated_at' => is_string($updated) && $updated !== '' ? mb_substr($updated, 0, 40) : null,
            ];
        }

        return $secrets;
    }
}
