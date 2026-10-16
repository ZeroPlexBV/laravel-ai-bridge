<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Middleware\Csrf;

use Illuminate\Http\Request;

/**
 * The verdict of Laravel's own CSRF middleware, without its side effects.
 *
 * Mixed into a subclass of the framework's middleware (see FrameworkCsrfCheck), so the
 * token is read and compared by Laravel's code: `_token`, `X-CSRF-TOKEN`, or the
 * decrypted `X-XSRF-TOKEN` header against the session token, plus the app's global
 * exceptions and, on Laravel 13, the `Sec-Fetch-Site` origin check and its
 * allowSameSite()/useOriginOnly() settings. What it leaves out on purpose: the
 * "skip while running unit tests" shortcut and setting the XSRF-TOKEN cookie.
 *
 * @internal
 */
trait ChecksTokenTheLaravelWay
{
    public function passes(Request $request): bool
    {
        if ($this->isReading($request) || $this->inExceptArray($request)) {
            return true;
        }

        // Laravel 13: a browser-set `Sec-Fetch-Site: same-origin` is enough, as it is for
        // the app's own routes. In origin-only mode a mismatch throws; that is a refusal.
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
}
