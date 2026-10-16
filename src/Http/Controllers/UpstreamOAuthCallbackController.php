<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Events\UpstreamAuthCompleted;
use Tetrix\AiBridge\Upstreams\PendingSignIns;
use Tetrix\AiBridge\Upstreams\UpstreamClient;

/**
 * GET /ai-bridge/upstream-oauth/callback — where an authorization server sends the person
 * back after signing in to one of their bridge's upstream MCP servers.
 *
 * The state must name a pending, unexpired sign-in on a connection the signed-in person can
 * reach through the connections resolver; otherwise 404 or 403 and nothing is forwarded.
 * Then the redirect's parameters go to that connection's bridge (`upstream_auth_code`), the
 * bridge's `upstream_auth_result` is awaited (ai-bridge.upstream_oauth.callback_timeout) and
 * a small page says how it went. Statuses: 200 connected, 400 the bridge refused (including
 * the person declining), 404 / 403 refused here, 502 bridge unreachable, 504 no answer.
 */
class UpstreamOAuthCallbackController extends Controller
{
    public function __construct(
        private readonly AiBridgeManager $manager,
        private readonly PendingSignIns $signIns,
        private readonly UpstreamClient $upstreams,
    ) {}

    public function __invoke(Request $request): Response
    {
        $state = $request->query('state');
        $signIn = is_string($state) ? $this->signIns->find($state) : null;

        if ($signIn === null) {
            return $this->page(404, false, null, 'unknown_state', 'This sign-in link is unknown or was already used. Start the sign-in again from the application.');
        }

        $server = (string) $signIn['server'];

        if ($this->signIns->isExpired($signIn)) {
            $this->signIns->forget((string) $state);

            return $this->page(404, false, $server, 'expired', 'This sign-in link has expired. Start the sign-in again from the application.');
        }

        if (! $this->belongsToSignedInPerson($request, $signIn)) {
            Log::warning('AI Bridge: an upstream OAuth callback for someone else\'s sign-in — refused', [
                'server' => $server,
                'connection_id' => $signIn['connection_id'] ?? null,
            ]);

            return $this->page(403, false, $server, 'forbidden', 'This sign-in belongs to someone else.');
        }

        $query = [];
        foreach (['code', 'iss', 'error', 'error_description'] as $field) {
            $value = $request->query($field);
            if (is_string($value) && $value !== '') {
                $query[$field] = $value;
            }
        }

        if (! isset($query['code']) && ! isset($query['error'])) {
            return $this->page(400, false, $server, 'invalid_request', 'The sign-in came back without a code.');
        }

        $answer = $this->upstreams->completeSignIn($signIn, $query);
        $ok = ($answer['ok'] ?? false) === true;
        $code = $ok ? null : (is_string($answer['code'] ?? null) ? $answer['code'] : 'failed');
        $error = $ok ? null : (is_string($answer['error'] ?? null) ? $answer['error'] : null);

        try {
            UpstreamAuthCompleted::dispatch(
                is_int($signIn['connection_id'] ?? null) ? $signIn['connection_id'] : null,
                (string) $signIn['connection_key'],
                $request->user()?->getAuthIdentifier(),
                $server,
                $ok,
                $code,
                $error,
            );
        } catch (\Throwable $e) {
            Log::warning('AI Bridge: an UpstreamAuthCompleted listener failed', ['error' => $e->getMessage()]);
        }

        if ($ok) {
            return $this->page(200, true, $server, null, null);
        }

        $status = match ($code) {
            'timeout' => 504,
            'not_connected', 'unreachable', 'send_failed' => 502,
            default => 400,
        };

        return $this->page($status, false, $server, $code, $error ?? self::defaultMessage($code));
    }

    /**
     * Whether the signed-in person may finish this sign-in: its connection is one the
     * application's connections resolver gives them. A bridge with no connection row (one
     * connected under a user id) belongs to that user.
     *
     * @param  array<string, mixed>  $signIn
     */
    private function belongsToSignedInPerson(Request $request, array $signIn): bool
    {
        $connectionId = $signIn['connection_id'] ?? null;

        if (is_int($connectionId)) {
            return $this->manager->connectionsQuery($request)->whereKey($connectionId)->exists();
        }

        $userId = $request->user()?->getAuthIdentifier();

        return $userId !== null && (string) $userId === (string) $signIn['connection_key'];
    }

    private static function defaultMessage(?string $code): string
    {
        return match ($code) {
            'denied' => 'The sign-in was declined.',
            'unknown_state' => 'This sign-in link expired or was replaced by a newer one.',
            'timeout' => 'Your machine did not confirm the sign-in in time. It may still complete; check the connection in the application.',
            'not_connected' => 'The machine that asked for this sign-in is not connected.',
            default => 'The sign-in could not be completed.',
        };
    }

    private function page(int $status, bool $ok, ?string $server, ?string $code, ?string $message): Response
    {
        $view = (string) config('ai-bridge.upstream_oauth.view', 'ai-bridge::upstream-oauth.result');

        return response()->view($view, [
            'ok' => $ok,
            'server' => $server,
            'code' => $code,
            'message' => $message,
        ], $status)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
