<?php

use Illuminate\Support\Facades\Route;
use Tetrix\AiBridge\Http\Controllers\AssetController;
use Tetrix\AiBridge\Http\Controllers\AttachmentController;
use Tetrix\AiBridge\Http\Controllers\BridgeController;
use Tetrix\AiBridge\Http\Controllers\ConnectionController;
use Tetrix\AiBridge\Http\Controllers\ConversationController;
use Tetrix\AiBridge\Http\Controllers\StreamController;
use Tetrix\AiBridge\Http\Controllers\StreamEventsController;
use Tetrix\AiBridge\Http\Controllers\UpstreamController;
use Tetrix\AiBridge\Http\Controllers\UpstreamOAuthCallbackController;
use Tetrix\AiBridge\Upstreams\UpstreamOAuth;

// Static assets for the chat Web Component — public (no auth): the component JS.
// Kept outside the auth-gated group below.
Route::middleware('api')->prefix('ai-bridge')->group(function () {
    Route::get('/assets/{file}', [AssetController::class, 'show'])
        ->where('file', '[A-Za-z0-9._-]+');
});

/*
|--------------------------------------------------------------------------
| Attachments — spoken to by a bridge, not by a browser
|--------------------------------------------------------------------------
|
| Guarded by the bridge connection token rather than the app's session
| middleware: the caller here is the CLI bridge on someone's machine, using
| the same credential it holds the WebSocket open with. Deliberately outside
| the `route_middleware` group below, which is the browser's auth.
|
| Where the bytes live is the app's business — see
| AiBridge::resolveAttachmentsUsing() and AiBridge::storeAttachmentUsing().
| Without those registered, downloads 404 and uploads 501.
|
| Throttled generously: one chat turn can legitimately carry several
| attachments, and each is a separate request.
*/
Route::middleware(['api', 'ai-bridge.token', 'throttle:60,1'])
    ->prefix('ai-bridge')
    ->group(function () {
        Route::get('/attachments/{id}', [AttachmentController::class, 'show'])
            ->where('id', '[A-Za-z0-9._-]+');
        Route::post('/attachments', [AttachmentController::class, 'store']);
    });

/*
|--------------------------------------------------------------------------
| AI Bridge API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the AiBridgeServiceProvider within the 'api'
| middleware group. Authentication middleware is applied by default via
| the 'ai-bridge.route_middleware' config key (defaults to ['auth']).
|
| To customize the auth middleware (e.g. for Sanctum):
|   'route_middleware' => ['auth:sanctum'],
|
| To disable the default auth middleware entirely:
|   'route_middleware' => [],
|
*/

$middleware = array_filter(array_merge(
    ['api'],
    (array) config('ai-bridge.route_middleware', ['auth']),
));

Route::middleware($middleware)->prefix('ai-bridge')->group(function () {
    // Token and status endpoints — throttle:20,1 (20 requests per minute)
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/token', [BridgeController::class, 'generateToken']);
        Route::get('/status', [BridgeController::class, 'status']);
    });

    // Conversation-less SSE streaming endpoint — throttle:10,1.
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/stream/sse', [StreamController::class, 'sse']);
    });

    // Conversation + connection management — throttle:120,1 (120 requests per
    // minute). Generous enough for a browsing UI that fetches a conversation
    // and its messages on each click. Access is scoped through the project-
    // supplied resolvers (AiBridge::resolveConversationsUsing / …Connections).
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/conversations/{id}', [ConversationController::class, 'show']);
        Route::delete('/conversations/{id}', [ConversationController::class, 'destroy']);

        Route::get('/connections', [ConnectionController::class, 'index']);
        Route::post('/connections', [ConnectionController::class, 'store']);
        Route::patch('/connections/{id}', [ConnectionController::class, 'update']);
        Route::post('/connections/{id}/regenerate', [ConnectionController::class, 'regenerate']);
        Route::delete('/connections/{id}', [ConnectionController::class, 'destroy']);

        // A bridge's upstream MCP servers (`--mcp-upstreams`): their status, the OAuth
        // sign-ins waiting on the person, starting one, and forgetting an upstream's tokens.
        Route::get('/connections/{id}/upstreams', [UpstreamController::class, 'index'])
            ->name('ai-bridge.upstreams.index');
        Route::get('/connections/{id}/upstreams/pending', [UpstreamController::class, 'pending'])
            ->name('ai-bridge.upstreams.pending');
        Route::post('/connections/{id}/upstreams/{server}/sign-in', [UpstreamController::class, 'signIn'])
            ->where('server', '[A-Za-z0-9._-]+')
            ->name('ai-bridge.upstreams.sign-in');
        Route::delete('/connections/{id}/upstreams/{server}/sign-in', [UpstreamController::class, 'forget'])
            ->where('server', '[A-Za-z0-9._-]+')
            ->name('ai-bridge.upstreams.forget');
    });

    // A view's request to an upstream MCP server, relayed through the bridge that made
    // the call (`mcp_request`). Held open (up to mcp_request_timeout) while the upstream
    // answers, so throttled lower than the browsing routes. Like every route in this
    // group it has no CSRF protection of its own: see the README.
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/connections/{id}/mcp', [UpstreamController::class, 'mcp'])
            ->name('ai-bridge.upstreams.mcp');
    });

    // Conversation streaming — throttle:30,1 (30 messages per minute). Each call
    // sends one chat message; the AI response itself takes seconds, so this is
    // a comfortable ceiling for an active conversation.
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/conversations/{id}/stream', [ConversationController::class, 'stream']);
    });

    // Per-turn stream-event endpoints.
    //
    // /events is a PHP-FPM long-poll that may hold an FPM worker for up to
    // ai-bridge.stream_store.max_connection_s; client EventSource reconnects
    // pick up via Last-Event-ID for free. Throttling is comfortably above any
    // realistic UI churn.
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('/streams/{requestId}/status', [StreamEventsController::class, 'status'])
            ->where('requestId', '[A-Za-z0-9_-]+');
        Route::get('/streams/{requestId}/events', [StreamEventsController::class, 'events'])
            ->where('requestId', '[A-Za-z0-9_-]+');
        Route::post('/streams/{requestId}/abort', [StreamEventsController::class, 'abort'])
            ->where('requestId', '[A-Za-z0-9_-]+');
    });
});

/*
|--------------------------------------------------------------------------
| OAuth callback for a bridge's upstream MCP servers
|--------------------------------------------------------------------------
|
| Where an authorization server sends the person's browser back after they
| signed in to an upstream MCP server. A browser page, so it runs under the
| session middleware ('web' + 'auth' by default), not the API group above.
| Off when ai-bridge.upstream_oauth.enabled is false.
|
*/
if (UpstreamOAuth::enabled()) {
    Route::middleware([...(array) config('ai-bridge.upstream_oauth.middleware', ['web', 'auth']), 'throttle:30,1'])
        ->get(
            '/'.ltrim((string) config('ai-bridge.upstream_oauth.path', '/ai-bridge/upstream-oauth/callback'), '/'),
            UpstreamOAuthCallbackController::class,
        )
        ->name(UpstreamOAuth::CALLBACK_ROUTE);
}
