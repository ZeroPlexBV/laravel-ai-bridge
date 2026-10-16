<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tetrix\AiBridge\Http\Middleware\Csrf\FrameworkCsrfCheck;

/**
 * `ai-bridge.csrf:<scope>` — CSRF protection the package's own browser routes carry,
 * whatever `ai-bridge.route_middleware` the app chose.
 *
 * Those routes run in the `api` group, which checks no CSRF token. When the app lets a
 * cookie sign people in there (`route_middleware` with `web` or StartSession, Sanctum's
 * stateful SPA auth, a remember-me cookie), any page on the same site can make a
 * signed-in browser call them: SameSite=Lax cookies go along to a same-site subdomain.
 *
 * A request is checked when it rides on cookies: it carries at least one cookie and
 * either its session is started or a user was resolved by a session (stateful) guard,
 * e.g. from a remember-me cookie without a session. It must then carry the session's
 * CSRF token the Laravel way (`X-CSRF-TOKEN`, `_token`, or `X-XSRF-TOKEN` from the
 * XSRF-TOKEN cookie), compared by Laravel's own middleware; otherwise 419 JSON (always,
 * when there is no session to hold a token). A request without any cookie, or
 * authenticated by a token guard with no session (a bearer token, a personal access
 * token), passes: it carries nothing a forging page could make the browser add.
 *
 * Scopes, each switched by config at request time:
 * - `upstreams`: the state-changing upstream routes; `ai-bridge.csrf.upstreams`, default
 *   true. Strict: the app's global CSRF exceptions and allowSameSite() do not apply.
 * - `routes`: the other state-changing browser routes; `ai-bridge.csrf.routes`, default
 *   false. Follows the app's global CSRF settings, like its own routes.
 *
 * Needs laravel/framework (Laravel's CSRF middleware lives there, not in illuminate/*);
 * without it every checked request is refused.
 */
class RequireCsrfToken
{
    public const ALIAS = 'ai-bridge.csrf';

    public function handle(Request $request, Closure $next, string $scope = 'upstreams'): Response
    {
        if (! self::enabled($scope) || ! self::ridesOnCookies($request)) {
            return $next($request);
        }

        if (! class_exists(FrameworkCsrfCheck::class)
            || ! app(FrameworkCsrfCheck::class)->passes($request, strict: $scope !== 'routes')) {
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
     * Whether the request could have been made by a browser on someone's behalf: it sent
     * cookies, and a session was started for it or a session guard signed someone in.
     */
    public static function ridesOnCookies(Request $request): bool
    {
        if ($request->cookies->count() === 0) {
            return false;
        }

        if ($request->hasSession() && $request->session()->isStarted()) {
            return true;
        }

        return self::signedInByStatefulGuard();
    }

    /** A session-driver guard already holding a user (a remember-me cookie, Basic auth). */
    private static function signedInByStatefulGuard(): bool
    {
        if (! app()->bound('auth')) {
            return false;
        }

        $auth = app('auth');
        foreach ((array) config('auth.guards', []) as $name => $guard) {
            if (($guard['driver'] ?? null) !== 'session') {
                continue;
            }
            $instance = $auth->guard($name);
            if ($instance instanceof StatefulGuard && method_exists($instance, 'hasUser') && $instance->hasUser()) {
                return true;
            }
        }

        return false;
    }
}
