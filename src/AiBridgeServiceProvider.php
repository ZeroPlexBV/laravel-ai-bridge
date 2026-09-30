<?php

declare(strict_types=1);

namespace Tetrix\AiBridge;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Console\GenerateTokenCommand;
use Tetrix\AiBridge\Console\ServeCommand;
use Tetrix\AiBridge\Console\SweepTurnMarkersCommand;
use Tetrix\AiBridge\Console\TestCommand;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Http\Middleware\ValidateBridgeToken;
use Tetrix\AiBridge\Streaming\StreamStoreManager;
use Tetrix\AiBridge\Tools\ToolContext;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/**
 * Laravel service provider for the AI Bridge package.
 *
 * Registers config, routes, and binds all core services as singletons.
 */
class AiBridgeServiceProvider extends ServiceProvider
{
    /**
     * Tracks whether the empty-route_middleware warning has already been logged
     * in this process, so it fires at most once instead of per request.
     */
    private static bool $emptyMiddlewareWarningLogged = false;

    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge package config with app config
        $this->mergeConfigFrom(
            __DIR__.'/../config/ai-bridge.php',
            'ai-bridge'
        );

        // Bind core services as singletons
        $this->app->singleton(ToolRegistry::class, function () {
            return new ToolRegistry();
        });

        // The current tool-call's conversation, injected by the runtime around
        // each execution so handlers can scope their work without the AI knowing.
        //
        // MUST be a singleton: MessageHandler::executeToolCall() resolves it from
        // the container and sets the conversation id, while consuming-app handlers
        // (e.g. a tenant/record resolver) receive it via constructor injection.
        // If this were a fresh instance per resolution, the id set on the
        // runtime's copy would never reach the handler's copy and every
        // context-dependent handler tool would see no conversation. Closure tools
        // don't read context, so they'd appear to work while handler tools failed.
        $this->app->singleton(ToolContext::class);

        $this->app->singleton(TokenManager::class, function () {
            return new TokenManager();
        });

        // Registered as a singleton, but in-memory state only persists in
        // long-running processes — see the BridgeConnectionManager docblock.
        $this->app->singleton(BridgeConnectionManager::class, function () {
            return new BridgeConnectionManager();
        });

        $this->app->singleton(MessageHandler::class, function ($app) {
            return new MessageHandler(
                $app->make(BridgeConnectionManager::class),
                $app->make(TokenManager::class),
                $app->make(ToolRegistry::class),
            );
        });

        $this->app->singleton(AiBridgeManager::class, function ($app) {
            return new AiBridgeManager(
                $app->make(ToolRegistry::class),
                $app->make(BridgeConnectionManager::class),
                $app->make(TokenManager::class),
            );
        });

        // StreamStore — driver-based per-turn event buffer. Bound by the
        // manager so apps may register additional drivers via
        // StreamStore::extend(); the contract binding resolves to the
        // currently-configured default driver.
        $this->app->singleton(StreamStoreManager::class, function ($app) {
            return new StreamStoreManager($app);
        });

        $this->app->bind(StreamStoreContract::class, function ($app) {
            return $app->make(StreamStoreManager::class)->driver();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/ai-bridge.php' => config_path('ai-bridge.php'),
        ], 'ai-bridge-config');

        // Publish JavaScript client
        $this->publishes([
            __DIR__.'/../resources/js/ai-bridge.js' => resource_path('js/vendor/ai-bridge.js'),
        ], 'ai-bridge-js');

        // Reference chat UI component — usable as <x-ai-bridge::chat />.
        // Fully optional (the backend works without it) and overridable by
        // publishing the views. Self-contained: a Web Component bundle
        // (Shadow DOM, no global CSS/JS) served by the package itself, so
        // the host app needs no build toolchain.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ai-bridge');
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/ai-bridge'),
        ], 'ai-bridge-views');

        // Register named middleware for bridge token validation
        $this->app['router']->aliasMiddleware('ai-bridge.token', ValidateBridgeToken::class);

        // Warn when route_middleware is empty — routes will be unprotected.
        // Fire at most once per process to avoid flooding logs under PHP-FPM.
        if (! self::$emptyMiddlewareWarningLogged) {
            $routeMiddleware = config('ai-bridge.route_middleware', ['auth']);
            if (empty($routeMiddleware)) {
                self::$emptyMiddlewareWarningLogged = true;
                Log::warning('AI Bridge: route_middleware is empty — all AI Bridge HTTP routes are unprotected. Set ai-bridge.route_middleware to ["auth"] or ["auth:sanctum"] for production.');
            }
        }

        // Register routes.
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        // Conversation persistence migrations — merged (loaded), never published,
        // so consuming apps cannot fork them and break future package updates.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateTokenCommand::class,
                TestCommand::class,
                ServeCommand::class,
                SweepTurnMarkersCommand::class,
            ]);
        }

        // Clear conversation turn bookmarks a turn left behind (a serve process that stopped
        // mid-turn, a process killed outright). Cheap: one query over the conversations that
        // have a bookmark at all, which is only the ones running a turn.
        if (config('ai-bridge.persistence.sweep_turn_markers', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('ai-bridge:sweep-turn-markers')
                    ->everyFiveMinutes()
                    ->withoutOverlapping()
                    ->runInBackground();
            });
        }
    }
}
