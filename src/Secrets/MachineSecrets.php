<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Secrets;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Models\Connection;

/**
 * The secret store on a person's machine, managed through its bridge (PROTOCOL.md, "Secrets on
 * the machine").
 *
 *     AiBridge::secrets()->list($connection);           // names, descriptions, dates
 *     AiBridge::secrets()->put($connection, 'mailtrap', $value, 'Mailtrap SMTP password');
 *
 * Every method answers `{ok: true, ...}` or `{ok: false, code, error}`, never throws for a
 * machine that is off, too old (`machine_secrets_unsupported`) or slow (`timeout`). A secret's
 * value goes in, never comes out: there is deliberately no method that reads one, and a value is
 * never logged or put in an answer. The caller decides who may do this; this class does not.
 */
class MachineSecrets
{
    public const OPS = ['list', 'put', 'describe', 'remove'];

    public function __construct(
        private readonly TokenManager $tokenManager,
    ) {}

    /** @return array{ok: bool, secrets?: list<array{name: string, description: string|null, updated_at: string|null}>, code?: string, error?: string} */
    public function list(Connection $connection): array
    {
        $answer = $this->relay($connection, ['op' => 'list']);
        if (($answer['ok'] ?? false) === true) {
            $answer['secrets'] = SecretFrames::secretsOf($answer['secrets'] ?? []);
        }

        return $answer;
    }

    /** @return array{ok: bool, code?: string, error?: string} */
    public function put(Connection $connection, string $name, #[\SensitiveParameter] string $value, ?string $description = null): array
    {
        return $this->relay($connection, ['op' => 'put', 'name' => $name, 'value' => $value] + ($description === null ? [] : ['description' => $description]));
    }

    /** @return array{ok: bool, code?: string, error?: string} */
    public function describe(Connection $connection, string $name, string $description): array
    {
        return $this->relay($connection, ['op' => 'describe', 'name' => $name, 'description' => $description]);
    }

    /** @return array{ok: bool, code?: string, error?: string} */
    public function remove(Connection $connection, string $name): array
    {
        return $this->relay($connection, ['op' => 'remove', 'name' => $name]);
    }

    /**
     * The `secret_*` frame for a request body (without its id), or why the body is refused.
     * Shared by the client (before anything is sent) and the serve process (which trusts nobody).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|string
     */
    public static function frameOf(array $body): array|string
    {
        $op = $body['op'] ?? null;
        if (! is_string($op) || ! in_array($op, self::OPS, true)) {
            return 'Body must carry "op": list, put, describe or remove.';
        }

        if ($op === 'list') {
            return ['type' => 'secret_list'];
        }

        $name = $body['name'] ?? null;
        if (! is_string($name) || ! SecretName::valid($name)) {
            return 'A secret name is 1 to 64 letters, digits, dots, dashes or underscores, starting with a letter or digit.';
        }

        $frame = ['type' => 'secret_'.$op, 'name' => $name];
        $description = $body['description'] ?? null;

        if ($description !== null) {
            if (! is_string($description) || mb_strlen($description) > SecretName::MAX_DESCRIPTION || str_contains($description, "\0")) {
                return 'A description is text of at most '.SecretName::MAX_DESCRIPTION.' characters.';
            }
            $frame['description'] = $description;
        } elseif ($op === 'describe') {
            return 'A describe carries "description".';
        }

        if ($op === 'put') {
            $value = $body['value'] ?? null;
            if (! is_string($value) || $value === '' || strlen($value) > SecretName::MAX_VALUE_BYTES || str_contains($value, "\0")) {
                return 'A put carries a non-empty "value" of at most '.SecretName::MAX_VALUE_BYTES.' bytes.';
            }
            $frame['value'] = $value;
        }

        return $frame;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function relay(Connection $connection, #[\SensitiveParameter] array $body): array
    {
        if (! $connection->isBridge() || ($connection->connection_key ?? '') === '') {
            return ['ok' => false, 'code' => 'invalid_request', 'error' => 'Only a CLI bridge connection has a machine.'];
        }

        $refused = self::frameOf($body);
        if (is_string($refused)) {
            return ['ok' => false, 'code' => 'invalid_request', 'error' => $refused];
        }

        try {
            $relayToken = $this->tokenManager->generate($connection->connection_key, ['scope' => TokenManager::INTERNAL_RELAY_SCOPE], 60);

            /** @var Response $response */
            $response = Http::withToken($relayToken)
                ->timeout((int) config('ai-bridge.machine_secrets.timeout', 15) + 3)
                ->acceptJson()
                ->post(self::internalApiBase().'/api/secrets', $body);
        } catch (\Throwable $e) {
            // The exception's message is not logged: a client error may echo the request.
            Log::info('AI Bridge: could not reach the serve process for a secret request', [
                'op' => $body['op'],
                'error' => $e::class,
            ]);

            return ['ok' => false, 'code' => 'unreachable', 'error' => 'The bridge server could not be reached.'];
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if ($response->successful() && ($json['ok'] ?? null) === true) {
            return ['ok' => true] + (isset($json['secrets']) ? ['secrets' => $json['secrets']] : []);
        }

        return [
            'ok' => false,
            'code' => is_string($json['code'] ?? null) ? $json['code'] : 'unreachable',
            'error' => is_string($json['error'] ?? null) ? $json['error'] : 'The bridge server answered '.$response->status().'.',
        ];
    }

    private static function internalApiBase(): string
    {
        $relayUrl = config('ai-bridge.server.relay_url');
        if (! empty($relayUrl)) {
            return rtrim((string) $relayUrl, '/');
        }

        $host = (string) config('ai-bridge.server.host', '127.0.0.1');
        $port = (int) config('ai-bridge.server.port', 8085);

        return 'http://'.($host === '0.0.0.0' ? '127.0.0.1' : $host).':'.$port;
    }
}
