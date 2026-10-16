<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tetrix\AiBridge\Http\Middleware\Csrf\FrameworkCsrfCheck;

/**
 * `ai-bridge.csrf:<scope>` — CSRF protection the package's own browser routes carry,
 * whatever `ai-bridge.route_middleware` the app chose.
 *
 * Those routes run in the `api` group, which checks no CSRF token. When the app lets a
 * session sign people in there (`route_middleware` with `web` or StartSession, Sanctum's
 * stateful SPA auth), any page on the same site can make a signed-in browser call them:
 * SameSite=Lax cookies go along to a same-site subdomain.
 *
 * A request is checked when it rides on cookies: its session is started and it carries
 * at least one cookie. It must then carry the session's CSRF token the Laravel way
 * (`X-CSRF-TOKEN`, `_token`, or `X-XSRF-TOKEN` from the XSRF-TOKEN cookie), compared by
 * Laravel's own middleware; otherwise 419 JSON. A request without a started session, or
 * without any cookie (a bearer token, a personal access token, server-to-server) passes:
 * it carries nothing a forging page could make the browser add.
 *
 * Scopes, each switched by config at request time:
 * - `upstreams`: the state-changing upstream routes; `ai-bridge.csrf.upstreams`, default true.
 * - `routes`: the other state-changing browser routes; `ai-bridge.csrf.routes`, default false.
 */
class RequireCsrfToken
{
    public const ALIAS = 'ai-bridge.csrf';

    public function handle(Request $request, Closure $next, string $scope = 'upstreams'): Response
    {
        if (! self::enabled($scope) || ! self::ridesOnCookies($request)) {
            return $next($request);
        }

        if (! class_exists(FrameworkCsrfCheck::class) || ! app(FrameworkCsrfCheck::class)->passes($request)) {
            return response()->json([
                'code' => 'csrf_token_mismatch',
                'message' => 'CSRF token mismatch.',
            ], 419);
        }

        return $next($request);
    }

    public static function enabled(string $scope): bool
    {
        return match ($scope) {
            'upstreams' => (bool) config('ai-bridge.csrf.upstreams', true),
            'routes' => (bool) config('ai-bridge.csrf.routes', false),
            default => true,
        };
    }

    /**
     * Whether the request could have been made by a browser on someone's behalf: a session
     * was started for it and it sent cookies (the session cookie, a remember-me cookie).
     */
    public static function ridesOnCookies(Request $request): bool
    {
        return $request->hasSession()
            && $request->session()->isStarted()
            && $request->cookies->count() > 0;
    }
}
