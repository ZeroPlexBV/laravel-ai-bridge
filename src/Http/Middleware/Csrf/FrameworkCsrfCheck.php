<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Middleware\Csrf;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

/*
 * Laravel 13 renamed its CSRF middleware to PreventRequestForgery (VerifyCsrfToken is a
 * deprecated alias of it); Laravel 12 has only VerifyCsrfToken. Extend whichever is the
 * real one, so the check is the framework's on both. Without laravel/framework neither
 * exists, the class is not declared, and RequireCsrfToken refuses (fails closed).
 */
if (class_exists(PreventRequestForgery::class)) {
    /** @internal */
    class FrameworkCsrfCheck extends PreventRequestForgery
    {
        use ChecksTokenTheLaravelWay;
    }
} elseif (class_exists(VerifyCsrfToken::class)) {
    /** @internal */
    class FrameworkCsrfCheck extends VerifyCsrfToken
    {
        use ChecksTokenTheLaravelWay;
    }
}
