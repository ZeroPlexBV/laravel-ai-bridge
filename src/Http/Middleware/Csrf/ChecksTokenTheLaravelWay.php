<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Middleware\Csrf;

use Illuminate\Http\Request;

/**
 * The verdict of Laravel's own CSRF middleware, without its side effects.
 *
 * Mixed into a subclass of the framework's middleware (see FrameworkCsrfCheck), so the
 * token is read and compared by Laravel's code: `_token`, `X-CSRF-TOKEN`, or the
 * decrypted `X-XSRF-TOKEN` header against the session token. What it leaves out on
 * purpose: the "skip while running unit tests" shortcut and setting the XSRF-TOKEN cookie.
 *
 * Strict (the `upstreams` scope): the app's global CSRF settings do NOT apply. Its
 * except() list is ignored (ai-bridge.csrf.upstreams is the switch for that), and the
 * only origin shortcut is a browser-set `Sec-Fetch-Site: same-origin`; allowSameSite()
 * and useOriginOnly() are ignored, because a same-site page is exactly the attacker.
 *
 * Not strict (the opt-in `routes` scope): Laravel's own rules, the app's settings
 * included (except(), and on Laravel 13 the Sec-Fetch-Site check with allowSameSite()
 * and useOriginOnly()).
 *
 * @internal
 */
trait ChecksTokenTheLaravelWay
{
    public function passes(Request $request, bool $strict = true): bool
    {
        if ($this->isReading($request)) {
            return true;
        }

        if ($strict) {
            return $request->header('Sec-Fetch-Site') === 'same-origin'
                || $this->tokensMatch($request);
        }

        if ($this->inExceptArray($request)) {
            return true;
        }

        // Laravel 13: its Sec-Fetch-Site check, as for the app's own routes. In
        // origin-only mode a mismatch throws; that is a refusal.
        if (method_exists($this, 'hasValidOrigin')) {
            try {
                if ($this->hasValidOrigin($request)) {
                    return true;
                }
            } catch (\Illuminate\Http\Exceptions\OriginMismatchException) {
                return false;
            }
        }

        return $this->tokensMatch($request);
    }

    /** Laravel's comparison; without a session there is no token to match. */
    protected function tokensMatch($request)
    {
        if (! $request->hasSession() || ! $request->session()->isStarted()) {
            return false;
        }

        return parent::tokensMatch($request);
    }
}
