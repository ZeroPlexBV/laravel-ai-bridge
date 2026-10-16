<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Upstreams;

use Illuminate\Support\Facades\Route;
use Tetrix\AiBridge\Support\BridgeLog;

/**
 * Where the OAuth sign-in to a bridge's upstream MCP servers comes back to.
 */
final class UpstreamOAuth
{
    /** The callback route's name. */
    public const CALLBACK_ROUTE = 'ai-bridge.upstream-oauth.callback';

    /** Whether the callback route is registered and `welcome.upstream_oauth` sent. */
    public static function enabled(): bool
    {
        return (bool) config('ai-bridge.upstream_oauth.enabled', true);
    }

    /**
     * The absolute callback URL a bridge registers as its redirect_uri, or null when switched off.
     *
     * `ai-bridge.upstream_oauth.redirect_uri` when set (behind a proxy whose public host
     * differs from APP_URL); otherwise APP_URL followed by the callback route's path. Never
     * the current request's host: the bridge registers this exact string with the
     * authorization server and keys its client registration on it, so it must be the same
     * on every connection, whichever process builds the welcome.
     *
     * Null (and a warning logged) when the result is not an absolute http(s) URL, which the
     * bridge would refuse anyway (its OAuth upstreams then report `no_redirect_uri`).
     */
    public static function redirectUri(): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        $uri = config('ai-bridge.upstream_oauth.redirect_uri');

        if (! is_string($uri) || $uri === '') {
            try {
                if (! Route::has(self::CALLBACK_ROUTE)) {
                    return null;
                }
                $uri = rtrim((string) config('app.url'), '/').route(self::CALLBACK_ROUTE, [], false);
            } catch (\Throwable $e) {
                BridgeLog::warning('could not build the upstream OAuth callback URL', ['error' => $e->getMessage()]);

                return null;
            }
        }

        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true) || ! is_string(parse_url($uri, PHP_URL_HOST))) {
            BridgeLog::warning('the upstream OAuth callback URL is not an absolute http(s) URL; set APP_URL or ai-bridge.upstream_oauth.redirect_uri', ['redirect_uri' => $uri]);

            return null;
        }

        return $uri;
    }
}
