<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Http\Middleware\RequireCsrfToken;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Upstreams\UpstreamViews;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| CSRF on the package's state-changing routes (ai-bridge.csrf, decision 0009)
|--------------------------------------------------------------------------
|
| The routes run in the 'api' group, which checks no CSRF token. When the app
| lets a session sign people in there, the package checks the token itself for
| every request that rides on cookies; a bearer-token request (no session, no
| cookie) passes. Laravel's own CSRF middleware skips itself while running unit
| tests; the package's check deliberately does not, which is what lets these
| tests see it.
|
*/

const CSRF_TOKEN = 'csrf-token-of-this-session-0123456789abcd';

/** Re-register the package's routes under another route_middleware. */
function csrfRoutesUnder(array $middleware): void
{
    config(['ai-bridge.route_middleware' => $middleware]);
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/api.php';
    $router->getRoutes()->refreshNameLookups();
}

/** The state-changing upstream routes, as [method, route name, body]. */
function upstreamRoutes(): array
{
    return [
        'mcp' => ['POST', 'ai-bridge.upstreams.mcp', ['server' => 'weather', 'method' => 'tools/call', 'params' => ['name' => 'refresh'], 'tool_call_id' => 'toolu_c1']],
        'sign-in' => ['POST', 'ai-bridge.upstreams.sign-in', []],
        'forget' => ['DELETE', 'ai-bridge.upstreams.forget', []],
    ];
}

/** The framework CSRF middleware class the package's check extends. */
function csrfBase(): string
{
    return get_parent_class(\Tetrix\AiBridge\Http\Middleware\Csrf\FrameworkCsrfCheck::class);
}

/** Apply the app's global CSRF settings (static) for $run, then reset them. */
function withGlobalCsrfSettings(callable $apply, callable $run): void
{
    $apply();
    try {
        $run();
    } finally {
        $base = csrfBase();
        if (method_exists($base, 'flushState')) {
            $base::flushState();
        } else {
            (new ReflectionProperty($base, 'neverVerify'))->setValue(null, []);
        }
    }
}

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app(AiBridgeManager::class)->resolveConnectionsUsing(fn ($request) => Connection::query());

    $this->connection = Connection::create(['type' => Connection::TYPE_BRIDGE, 'connection_key' => 'key-1', 'name' => 'mine']);
    $this->person = new GenericUser(['id' => 7]);
    UpstreamViews::remember($this->connection->id, 'toolu_c1', 'weather');

    Http::fake([
        '*/api/mcp' => Http::response(['ok' => true, 'result' => ['content' => []]]),
        '*/api/upstream/auth-start' => Http::response(['ok' => true, 'authorization_url' => 'https://kaizen.example/authorize', 'expires_at' => '2030-01-01T00:00:00Z', 'reason' => 'no_token']),
        '*/api/upstream/auth-forget' => Http::response(['sent' => true]),
        '*' => Http::response([]),
    ]);
});

function callUpstream($test, string $route, array $headers = [])
{
    [$method, $name, $body] = upstreamRoutes()[$route];
    $url = route($name, ['id' => $test->connection->id, 'server' => 'kaizen']);

    return $test->json($method, $url, $body, $headers);
}

describe('a session-authenticated request (route_middleware web + auth)', function () {
    beforeEach(fn () => csrfRoutesUnder(['web', 'auth']));

    it('is refused with 419 JSON without a CSRF token, sending nothing', function (string $route) {
        $this->actingAs($this->person)
            ->withSession(['_token' => CSRF_TOKEN])
            ->withCookie('laravel_session', 'x')->withCredentials();

        callUpstream($this, $route)
            ->assertStatus(419)
            ->assertJson(['code' => 'csrf_token_mismatch', 'message' => 'CSRF token mismatch.']);

        Http::assertNothingSent();
    })->with(array_keys(upstreamRoutes()));

    it('is refused with a wrong token', function (string $route) {
        $this->actingAs($this->person)
            ->withSession(['_token' => CSRF_TOKEN])
            ->withCookie('laravel_session', 'x')->withCredentials();

        callUpstream($this, $route, ['X-CSRF-TOKEN' => 'not-the-token'])->assertStatus(419);
    })->with(array_keys(upstreamRoutes()));

    it('passes with the session token in X-CSRF-TOKEN', function (string $route) {
        $this->actingAs($this->person)
            ->withSession(['_token' => CSRF_TOKEN])
            ->withCookie('laravel_session', 'x')->withCredentials();

        callUpstream($this, $route, ['X-CSRF-TOKEN' => CSRF_TOKEN])->assertOk();
    })->with(array_keys(upstreamRoutes()));

    it('passes with the encrypted XSRF-TOKEN cookie value in X-XSRF-TOKEN', function () {
        $encrypter = app('encrypter');
        $xsrf = $encrypter->encrypt(CookieValuePrefix::create('XSRF-TOKEN', $encrypter->getKey()).CSRF_TOKEN, false);

        $this->actingAs($this->person)
            ->withSession(['_token' => CSRF_TOKEN])
            ->withCookie('laravel_session', 'x')->withCredentials();

        callUpstream($this, 'mcp', ['X-XSRF-TOKEN' => $xsrf])->assertOk();
    });

    it('passes a request that carries no cookie at all (a token client)', function () {
        $this->actingAs($this->person);

        callUpstream($this, 'sign-in')->assertOk();
    });

    it('passes when ai-bridge.csrf.upstreams is off', function (string $route) {
        config(['ai-bridge.csrf.upstreams' => false]);
        $this->actingAs($this->person)
            ->withSession(['_token' => CSRF_TOKEN])
            ->withCookie('laravel_session', 'x')->withCredentials();

        callUpstream($this, $route)->assertOk();
    })->with(array_keys(upstreamRoutes()));

    it('passes a browser-set Sec-Fetch-Site: same-origin', function () {
        $this->actingAs($this->person)
            ->withSession(['_token' => CSRF_TOKEN])
            ->withCookie('laravel_session', 'x')->withCredentials();

        callUpstream($this, 'mcp', ['Sec-Fetch-Site' => 'same-origin'])->assertOk();
    });

    it('does not trust Sec-Fetch-Site: same-site, even when the app allowed it globally', function () {
        $this->actingAs($this->person)
            ->withSession(['_token' => CSRF_TOKEN])
            ->withCookie('laravel_session', 'x')->withCredentials();

        callUpstream($this, 'mcp', ['Sec-Fetch-Site' => 'same-site'])->assertStatus(419);

        withGlobalCsrfSettings(function () {
            if (method_exists(csrfBase(), 'allowSameSite')) {
                csrfBase()::allowSameSite();
            }
        }, function () {
            callUpstream($this, 'mcp', ['Sec-Fetch-Site' => 'same-site'])->assertStatus(419);
            callUpstream($this, 'sign-in', ['Sec-Fetch-Site' => 'same-site'])->assertStatus(419);
        });
    });

    it('ignores the app\'s global CSRF exceptions on the upstream routes', function (string $route) {
        $this->actingAs($this->person)
            ->withSession(['_token' => CSRF_TOKEN])
            ->withCookie('laravel_session', 'x')->withCredentials();

        withGlobalCsrfSettings(fn () => csrfBase()::except(['ai-bridge/*', '*']), function () use ($route) {
            callUpstream($this, $route)->assertStatus(419);
        });
    })->with(array_keys(upstreamRoutes()));
});

describe('a user signed in by cookie without a session (remember-me, route_middleware auth only)', function () {
    it('is refused: there is no session to hold a token', function (string $route) {
        // The web (session) guard holds a user, as after a remember_web_* cookie.
        $this->actingAs($this->person)->withUnencryptedCookie('remember_web_x', '7|token|hash')->withCredentials();

        callUpstream($this, $route)->assertStatus(419);
        callUpstream($this, $route, ['X-CSRF-TOKEN' => CSRF_TOKEN])->assertStatus(419);
    })->with(array_keys(upstreamRoutes()));

    it('passes without cookies, and when ai-bridge.csrf.upstreams is off', function () {
        $this->actingAs($this->person);
        callUpstream($this, 'mcp')->assertOk();

        config(['ai-bridge.csrf.upstreams' => false]);
        $this->withUnencryptedCookie('remember_web_x', '7|token|hash')->withCredentials();
        callUpstream($this, 'mcp')->assertOk();
    });
});

describe('a session started by the api group itself (route_middleware StartSession + auth)', function () {
    beforeEach(fn () => csrfRoutesUnder([StartSession::class, 'auth']));

    it('is refused without a token and passes with one', function () {
        $this->actingAs($this->person)
            ->withSession(['_token' => CSRF_TOKEN])
            ->withUnencryptedCookie('laravel_session', 'x')->withCredentials();

        callUpstream($this, 'mcp')->assertStatus(419);
        callUpstream($this, 'mcp', ['X-CSRF-TOKEN' => CSRF_TOKEN])->assertOk();
    });
});

describe('a request authenticated by a token guard (route_middleware auth:pat, a bearer token)', function () {
    beforeEach(function () {
        \Illuminate\Support\Facades\Auth::viaRequest('ai-bridge-test-token', fn ($request) => $request->bearerToken() === '1|personal-access-token' ? new GenericUser(['id' => 7]) : null);
        config(['auth.guards.pat' => ['driver' => 'ai-bridge-test-token']]);
        csrfRoutesUnder(['auth:pat']);
    });

    it('passes without a CSRF token, even carrying cookies', function (string $route) {
        $this->withUnencryptedCookie('laravel_session', 'x')->withCredentials();

        callUpstream($this, $route, ['Authorization' => 'Bearer 1|personal-access-token'])->assertOk();
    })->with(array_keys(upstreamRoutes()));
});

describe('the other state-changing routes (ai-bridge.csrf.routes, opt-in)', function () {
    beforeEach(fn () => csrfRoutesUnder(['web', 'auth']));

    $others = [
        'token' => ['POST', '/ai-bridge/token'],
        'stream/sse' => ['POST', '/ai-bridge/stream/sse'],
        'conversations store' => ['POST', '/ai-bridge/conversations'],
        'conversations destroy' => ['DELETE', '/ai-bridge/conversations/1'],
        'conversations stream' => ['POST', '/ai-bridge/conversations/1/stream'],
        'connections store' => ['POST', '/ai-bridge/connections'],
        'connections update' => ['PATCH', '/ai-bridge/connections/1'],
        'connections regenerate' => ['POST', '/ai-bridge/connections/1/regenerate'],
        'connections destroy' => ['DELETE', '/ai-bridge/connections/1'],
        'streams abort' => ['POST', '/ai-bridge/streams/req_1/abort'],
    ];

    it('are not checked by default', function (string $method, string $uri) {
        $this->actingAs($this->person)
            ->withSession(['_token' => CSRF_TOKEN])
            ->withCookie('laravel_session', 'x')->withCredentials();

        expect($this->json($method, $uri)->status())->not->toBe(419);
    })->with($others);

    it('are refused without a token once ai-bridge.csrf.routes is on', function (string $method, string $uri) {
        config(['ai-bridge.csrf.routes' => true]);
        $this->actingAs($this->person)
            ->withSession(['_token' => CSRF_TOKEN])
            ->withCookie('laravel_session', 'x')->withCredentials();

        $this->json($method, $uri)->assertStatus(419);
        expect($this->json($method, $uri, [], ['X-CSRF-TOKEN' => CSRF_TOKEN])->status())->not->toBe(419);
    })->with($others);

    it('follow the app\'s global CSRF exceptions, unlike the upstream routes', function () {
        config(['ai-bridge.csrf.routes' => true]);
        $this->actingAs($this->person)
            ->withSession(['_token' => CSRF_TOKEN])
            ->withCookie('laravel_session', 'x')->withCredentials();

        withGlobalCsrfSettings(fn () => csrfBase()::except(['ai-bridge/*']), function () {
            expect($this->postJson('/ai-bridge/conversations')->status())->not->toBe(419);
            callUpstream($this, 'mcp')->assertStatus(419);
        });
    });

    it('leave the reading routes alone', function () {
        config(['ai-bridge.csrf.routes' => true]);
        $this->actingAs($this->person)
            ->withSession(['_token' => CSRF_TOKEN])
            ->withCookie('laravel_session', 'x')->withCredentials();

        $this->getJson('/ai-bridge/connections')->assertOk();
        $this->getJson(route('ai-bridge.upstreams.index', ['id' => $this->connection->id]))->assertStatus(200);
    });
});

it('puts the upstream scope on exactly the three state-changing upstream routes', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array(RequireCsrfToken::ALIAS.':upstreams', $r->gatherMiddleware(), true))
        ->map(fn ($r) => $r->getName())
        ->sort()->values()->all();

    expect($routes)->toBe(['ai-bridge.upstreams.forget', 'ai-bridge.upstreams.mcp', 'ai-bridge.upstreams.sign-in']);
});

it('gives the reference chat UI the session\'s CSRF token, and renders without a session', function () {
    expect(\Illuminate\Support\Facades\Blade::render('<x-ai-bridge::chat />'))->toContain('csrf-token=""');

    $session = app('session')->driver();
    $session->start();
    request()->setLaravelSession($session);

    expect(\Illuminate\Support\Facades\Blade::render('<x-ai-bridge::chat />'))
        ->toContain('csrf-token="'.$session->token().'"');
});
