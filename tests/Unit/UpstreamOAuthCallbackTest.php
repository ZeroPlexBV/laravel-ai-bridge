<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Events\UpstreamAuthCompleted;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Upstreams\PendingSignIns;
use Tetrix\AiBridge\Upstreams\UpstreamOAuth;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The OAuth callback, and the JSON routes beside the connection routes
|--------------------------------------------------------------------------
|
| The authorization server sends the person's browser to the callback with
| ?state&code. The state must be pending, unexpired and on a connection the
| signed-in person can reach; only then is the code passed to the serve process
| (faked here) and the bridge's result awaited.
|
*/

const CB_STATE = 'Xb3kQ9vW2mN7pL4tR8sY1zA6cE5fH0jU';

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    // Person 7 owns connections named "owner-7"; person 8 owns "owner-8".
    app(AiBridgeManager::class)->resolveConnectionsUsing(
        fn ($request) => Connection::query()->where('name', 'owner-'.$request->user()?->getAuthIdentifier())
    );

    $this->connection = Connection::create(['type' => Connection::TYPE_BRIDGE, 'connection_key' => 'key-1', 'name' => 'owner-7']);
    $this->person = new GenericUser(['id' => 7]);
    $this->stranger = new GenericUser(['id' => 8]);
});

function pendingSignIn(Connection $connection, int $expiresIn = 600, string $state = CB_STATE): void
{
    app(PendingSignIns::class)->put([
        'state' => $state,
        'connection_key' => (string) $connection->connection_key,
        'connection_id' => $connection->id,
        'server' => 'kaizen',
        'authorization_url' => 'https://kaizen.example/oauth/authorize?state='.$state,
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $expiresIn),
        'reason' => 'no_token',
    ]);
}

function callbackUrl(array $query): string
{
    return route(UpstreamOAuth::CALLBACK_ROUTE, $query);
}

describe('GET the callback', function () {
    it('is named, on the default path, behind web + auth', function () {
        $route = app('router')->getRoutes()->getByName(UpstreamOAuth::CALLBACK_ROUTE);

        expect($route->uri())->toBe('ai-bridge/upstream-oauth/callback')
            ->and($route->gatherMiddleware())->toContain('web', 'auth');
    });

    it('passes the code to the bridge, renders Connected, and fires UpstreamAuthCompleted', function () {
        Event::fake([UpstreamAuthCompleted::class]);
        Http::fake(['*/api/upstream/auth-code' => Http::response(['ok' => true, 'server' => 'kaizen'])]);
        pendingSignIn($this->connection);

        $this->actingAs($this->person)
            ->get(callbackUrl(['state' => CB_STATE, 'code' => 'def502', 'iss' => 'https://kaizen.example']))
            ->assertOk()
            ->assertSee('Connected to kaizen')
            ->assertSee('You can close this tab.')
            ->assertHeader('Cache-Control');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/upstream/auth-code')
            && $r->data() === ['server' => 'kaizen', 'state' => CB_STATE, 'code' => 'def502', 'iss' => 'https://kaizen.example']);
        Event::assertDispatched(UpstreamAuthCompleted::class, fn (UpstreamAuthCompleted $e) => $e->ok
            && $e->server === 'kaizen' && $e->connectionId === $this->connection->id && $e->userId === 7 && $e->code === null);
        // A state is used once.
        expect(app(PendingSignIns::class)->find(CB_STATE))->toBeNull();
    });

    it('answers 404 for an unknown state, without forwarding anything', function () {
        Http::fake();

        $this->actingAs($this->person)
            ->get(callbackUrl(['state' => 'nothing-like-it', 'code' => 'def502']))
            ->assertNotFound()
            ->assertSee('unknown or was already used');

        Http::assertNothingSent();
    });

    it('answers 404 for a used state', function () {
        Http::fake(['*/api/upstream/auth-code' => Http::response(['ok' => true, 'server' => 'kaizen'])]);
        pendingSignIn($this->connection);

        $this->actingAs($this->person)->get(callbackUrl(['state' => CB_STATE, 'code' => 'a']))->assertOk();
        $this->actingAs($this->person)->get(callbackUrl(['state' => CB_STATE, 'code' => 'a']))->assertNotFound();

        Http::assertSentCount(1);
    });

    it('claims a state once, and not at all while another request holds it', function () {
        pendingSignIn($this->connection);
        $signIns = app(PendingSignIns::class);

        // A racing callback holds the claim: this one gets nothing and forwards nothing.
        $lock = \Illuminate\Support\Facades\Cache::lock('ai-bridge:upstream-oauth:state:'.hash('sha256', CB_STATE).':claim', 30);
        expect($lock->get())->toBeTrue()
            ->and($signIns->claim(CB_STATE))->toBeNull()
            ->and($signIns->find(CB_STATE))->not->toBeNull();
        $lock->release();

        expect($signIns->claim(CB_STATE)['server'])->toBe('kaizen')
            ->and($signIns->claim(CB_STATE))->toBeNull()
            ->and($signIns->forConnectionKey('key-1'))->toBe([]);
    });

    it('answers 404 when the state is claimed between the checks and the forwarding', function () {
        Http::fake();
        pendingSignIn($this->connection);
        \Illuminate\Support\Facades\Cache::lock('ai-bridge:upstream-oauth:state:'.hash('sha256', CB_STATE).':claim', 30)->get();

        $this->actingAs($this->person)->get(callbackUrl(['state' => CB_STATE, 'code' => 'a']))->assertNotFound();

        Http::assertNothingSent();
    });

    it('keeps both sign-ins in the index when two upstreams write it', function () {
        pendingSignIn($this->connection);
        app(PendingSignIns::class)->put([
            'state' => 'Zz9yX8wV7uT6sR5qP4oN3mL2kJ1iH0gF', 'connection_key' => 'key-1', 'connection_id' => $this->connection->id,
            'server' => 'other', 'authorization_url' => 'https://o.example/a', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600), 'reason' => 'no_token',
        ]);

        expect(array_keys(app(PendingSignIns::class)->forConnectionKey('key-1')))->toBe(['kaizen', 'other']);
    });

    it('answers 404 for an expired state, without forwarding anything', function () {
        Http::fake();
        pendingSignIn($this->connection, 600);
        $this->travel(11)->minutes();

        $this->actingAs($this->person)
            ->get(callbackUrl(['state' => CB_STATE, 'code' => 'def502']))
            ->assertNotFound();

        Http::assertNothingSent();
    });

    it('answers 403 for someone else\'s sign-in, without forwarding anything', function () {
        Event::fake([UpstreamAuthCompleted::class]);
        Http::fake();
        pendingSignIn($this->connection);

        $this->actingAs($this->stranger)
            ->get(callbackUrl(['state' => CB_STATE, 'code' => 'def502']))
            ->assertForbidden()
            ->assertSee('belongs to someone else');

        Http::assertNothingSent();
        Event::assertNotDispatched(UpstreamAuthCompleted::class);
        // Still pending: the right person may yet finish it.
        expect(app(PendingSignIns::class)->find(CB_STATE))->not->toBeNull();
    });

    it('redirects a guest to log in rather than forwarding', function () {
        Http::fake();
        pendingSignIn($this->connection);
        app('router')->get('/login', fn () => 'login')->name('login');

        $this->get(callbackUrl(['state' => CB_STATE, 'code' => 'def502']))->assertRedirect();

        Http::assertNothingSent();
    });

    it('passes a denial on and renders the bridge\'s refusal', function () {
        Event::fake([UpstreamAuthCompleted::class]);
        Http::fake(['*/api/upstream/auth-code' => Http::response(['ok' => false, 'server' => 'kaizen', 'code' => 'denied', 'error' => 'The person refused at the authorization server.'])]);
        pendingSignIn($this->connection);

        $this->actingAs($this->person)
            ->get(callbackUrl(['state' => CB_STATE, 'error' => 'access_denied', 'error_description' => 'The user denied the request']))
            ->assertStatus(400)
            ->assertSee('Sign-in not completed for kaizen')
            ->assertSee('The person refused at the authorization server.');

        Http::assertSent(fn (HttpRequest $r) => $r->data() === ['server' => 'kaizen', 'state' => CB_STATE, 'error' => 'access_denied', 'error_description' => 'The user denied the request']);
        Event::assertDispatched(UpstreamAuthCompleted::class, fn (UpstreamAuthCompleted $e) => ! $e->ok && $e->code === 'denied');
    });

    it('says so when the bridge does not report back in time', function () {
        Event::fake([UpstreamAuthCompleted::class]);
        Http::fake(['*/api/upstream/auth-code' => Http::response(['ok' => false, 'code' => 'timeout', 'error' => 'The bridge did not report the result in time. The sign-in may still complete.'], 504)]);
        pendingSignIn($this->connection);

        $this->actingAs($this->person)
            ->get(callbackUrl(['state' => CB_STATE, 'code' => 'def502']))
            ->assertStatus(504)
            ->assertSee('may still complete');

        Event::assertDispatched(UpstreamAuthCompleted::class, fn (UpstreamAuthCompleted $e) => ! $e->ok && $e->code === 'timeout');
    });

    it('waits no longer than callback_timeout plus a margin for the serve process', function () {
        config()->set('ai-bridge.upstream_oauth.callback_timeout', 15);
        Http::fake(fn () => throw new ConnectionException('timed out'));
        pendingSignIn($this->connection);

        $this->actingAs($this->person)
            ->get(callbackUrl(['state' => CB_STATE, 'code' => 'def502']))
            ->assertStatus(502);
    });

    it('refuses a redirect with neither code nor error', function () {
        Http::fake();
        pendingSignIn($this->connection);

        $this->actingAs($this->person)->get(callbackUrl(['state' => CB_STATE]))->assertStatus(400);

        Http::assertNothingSent();
    });

    it('renders an overriding view when configured', function () {
        Http::fake(['*/api/upstream/auth-code' => Http::response(['ok' => true])]);
        pendingSignIn($this->connection);
        app('view')->addNamespace('host', __DIR__.'/fixtures/views');
        config()->set('ai-bridge.upstream_oauth.view', 'host::oauth-done');

        $this->actingAs($this->person)
            ->get(callbackUrl(['state' => CB_STATE, 'code' => 'def502']))
            ->assertOk()
            ->assertSee('HOST PAGE ok=1 server=kaizen');
    });
});

describe('JSON routes', function () {
    it('reports status per upstream and pending sign-ins for the person\'s own connection only', function () {
        $upstreams = [['server' => 'kaizen', 'transport' => 'http', 'origin' => 'https://kaizen.example', 'auth' => 'oauth', 'state' => 'needs_auth', 'tools' => 0, 'error' => null]];
        Http::fake(['*/api/status' => Http::response([
            'connected' => true, 'providers' => [],
            'bridge' => ['mcp_apps' => ['spec' => '2026-01-26', 'revision' => 1], 'mcp_upstream_auth' => ['revision' => 1]],
            'upstream_status' => $upstreams,
        ])]);
        pendingSignIn($this->connection);

        $this->actingAs($this->person)
            ->getJson(route('ai-bridge.upstreams.index', ['id' => $this->connection->id]))
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('mcp_apps', true)
            ->assertJsonPath('mcp_upstream_auth', true)
            ->assertJsonPath('upstreams', $upstreams)
            ->assertJsonPath('pending.0.server', 'kaizen')
            ->assertJsonPath('pending.0.connection_id', $this->connection->id)
            ->assertJsonMissingPath('pending.0.state');

        $this->actingAs($this->person)
            ->getJson(route('ai-bridge.upstreams.pending', ['id' => $this->connection->id]))
            ->assertOk()
            ->assertJsonCount(1, 'pending');

        $this->actingAs($this->stranger)
            ->getJson(route('ai-bridge.upstreams.index', ['id' => $this->connection->id]))
            ->assertNotFound();
    });

    it('starts a sign-in and returns the authorization URL', function () {
        Http::fake(['*/api/upstream/auth-start' => Http::response(['ok' => true, 'server' => 'kaizen', 'authorization_url' => 'https://kaizen.example/oauth/authorize?x', 'expires_at' => '2026-10-16T18:10:00Z', 'reason' => 'requested'])]);

        $this->actingAs($this->person)
            ->postJson(route('ai-bridge.upstreams.sign-in', ['id' => $this->connection->id, 'server' => 'kaizen']))
            ->assertOk()
            ->assertJsonPath('authorization_url', 'https://kaizen.example/oauth/authorize?x');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/upstream/auth-start') && $r->data() === ['server' => 'kaizen']);
    });

    it('passes on why a sign-in could not start', function () {
        Http::fake(['*/api/upstream/auth-start' => Http::response(['ok' => false, 'code' => 'mcp_upstream_auth_unsupported', 'error' => 'update'], 409)]);

        $this->actingAs($this->person)
            ->postJson(route('ai-bridge.upstreams.sign-in', ['id' => $this->connection->id, 'server' => 'kaizen']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'mcp_upstream_auth_unsupported');
    });

    it('forgets an upstream\'s tokens and drops its pending sign-in', function () {
        Http::fake(['*/api/upstream/auth-forget' => Http::response(['sent' => true])]);
        pendingSignIn($this->connection);

        $this->actingAs($this->person)
            ->deleteJson(route('ai-bridge.upstreams.forget', ['id' => $this->connection->id, 'server' => 'kaizen']))
            ->assertOk()
            ->assertJson(['sent' => true]);

        expect(app(PendingSignIns::class)->find(CB_STATE))->toBeNull();
    });

    it('passes tool_call_id on, refusing a server other than the view\'s', function () {
        Http::fake(['*/api/mcp' => Http::response(['ok' => true, 'result' => []])]);
        \Tetrix\AiBridge\Upstreams\UpstreamViews::remember($this->connection->id, 'toolu_u1', 'weather');
        $url = route('ai-bridge.upstreams.mcp', ['id' => $this->connection->id]);

        $this->actingAs($this->person)->postJson($url, ['server' => 'weather', 'method' => 'tools/call', 'params' => ['name' => 'refresh'], 'tool_call_id' => 'toolu_u1'])
            ->assertOk();
        $this->actingAs($this->person)->postJson($url, ['server' => 'payments', 'method' => 'tools/call', 'params' => ['name' => 'pay'], 'tool_call_id' => 'toolu_u1'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'refused');

        Http::assertSentCount(1);
    });

    it('relays a view\'s request and maps failures to statuses', function () {
        Http::fake(['*/api/mcp' => Http::sequence()
            ->push(['ok' => true, 'result' => ['content' => [['type' => 'text', 'text' => 'Refreshed']]]])
            ->push(['ok' => false, 'code' => 'refused', 'error' => 'not for views'])]);
        \Tetrix\AiBridge\Upstreams\UpstreamViews::remember($this->connection->id, 'toolu_u1', 'weather');
        $url = route('ai-bridge.upstreams.mcp', ['id' => $this->connection->id]);
        $body = ['server' => 'weather', 'method' => 'tools/call', 'params' => ['name' => 'refresh', 'arguments' => ['city' => 'Utrecht']], 'tool_call_id' => 'toolu_u1'];

        $this->actingAs($this->person)->postJson($url, $body)
            ->assertOk()
            ->assertJsonPath('result.content.0.text', 'Refreshed');
        $this->actingAs($this->person)->postJson($url, $body)
            ->assertStatus(400)
            ->assertJson(['code' => 'refused', 'error' => 'not for views']);
        $this->actingAs($this->person)->postJson($url, ['server' => 'weather', 'method' => 'ping', 'tool_call_id' => 'toolu_u1'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'unsupported');

        Http::assertSentCount(2);
    });

    it('requires tool_call_id on the route, sending nothing without it', function (array $extra) {
        Http::fake();
        $url = route('ai-bridge.upstreams.mcp', ['id' => $this->connection->id]);

        $this->actingAs($this->person)
            ->postJson($url, ['server' => 'weather', 'method' => 'tools/call', 'params' => ['name' => 'refresh']] + $extra)
            ->assertStatus(422)
            ->assertJsonPath('code', 'tool_call_id_required');

        Http::assertNothingSent();
    })->with([
        'missing' => [[]],
        'null' => [['tool_call_id' => null]],
        'empty' => [['tool_call_id' => '']],
        'blank' => [['tool_call_id' => '  ']],
    ]);
});
