<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Console;

use Illuminate\Console\Command;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Server\BridgeWebSocketServer;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/**
 * Artisan command to start the dedicated WebSocket server for CLI bridge connections.
 *
 * This starts a ReactPHP WebSocket server on a configurable port (default 8085).
 * Bridge clients (npx @tetrixdev/ai-bridge) connect to this server.
 *
 * Usage:
 *   php artisan ai-bridge:serve
 *   php artisan ai-bridge:serve --port=9090
 *   php artisan ai-bridge:serve --host=127.0.0.1 --port=8085
 */
class ServeCommand extends Command
{
    protected $signature = 'ai-bridge:serve
        {--host= : Bind address (default: from config server.host, which defaults to 127.0.0.1)}
        {--port= : Port number (default: from config or 8085)}';

    protected $description = 'Start the AI Bridge WebSocket server for CLI bridge connections';

    public function handle(
        BridgeConnectionManager $connectionManager,
        MessageHandler $messageHandler,
        TokenManager $tokenManager,
    ): int {
        // Defaults to 127.0.0.1; 0.0.0.0 is opt-in for Docker/multi-host
        // deployments via AI_BRIDGE_SERVER_HOST.
        $host = $this->option('host') ?? config('ai-bridge.server.host', '127.0.0.1');
        $port = (int) ($this->option('port') ?? config('ai-bridge.server.port', 8085));

        $server = new BridgeWebSocketServer(
            $connectionManager,
            $messageHandler,
            $tokenManager,
            $host,
            $port,
        );

        // Handle SIGINT/SIGTERM for graceful shutdown. The turns this process relays end with
        // it (nothing else can relay the rest of them), so they are ended first: their buffers
        // say so and their conversations stop saying a turn is running. The stop waits a
        // moment so the cancel frames reach the machines before the sockets close.
        if (function_exists('pcntl_signal')) {
            $shutdown = function () use ($server, $connectionManager): void {
                $this->newLine();
                $this->info('Shutting down AI Bridge server...');

                $ended = $connectionManager->failAllPendingRequests();
                if ($ended > 0) {
                    $this->info("Ended {$ended} running turn(s).");
                }

                $loop = $server->getLoop();
                if ($ended > 0 && $loop !== null) {
                    $loop->addTimer(0.5, fn () => $server->stop());
                } else {
                    $server->stop();
                }
            };

            pcntl_signal(SIGINT, $shutdown);
            pcntl_signal(SIGTERM, $shutdown);
        }

        $this->showBanner($host, $port);

        $server->start(function () {
            $this->info('Server is ready and listening for connections.');
            $this->newLine();
        });

        $this->info('Server stopped.');

        return 0;
    }

    /**
     * Display the server startup banner.
     */
    private function showBanner(string $host, int $port): void
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>AI Bridge WebSocket Server</>');
        $this->line('  ========================');
        $this->newLine();
        $this->line("  Host:    <fg=green>{$host}</>");
        $this->line("  Port:    <fg=green>{$port}</>");
        $this->line("  URL:     <fg=green>ws://{$host}:{$port}</>");
        $this->newLine();

        // Warn when bound to a non-loopback address — browsers block plaintext
        // ws:// on HTTPS pages (mixed-content policy).
        $isPublicBind = ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if ($isPublicBind && empty(config('ai-bridge.server.public_url'))) {
            $this->line('  <fg=yellow;options=bold>⚠  TLS warning:</> Binding to a public interface with ws:// (plaintext).');
            $this->line('     Browsers block ws:// connections on HTTPS pages (mixed-content policy).');
            $this->line('     For production: set AI_BRIDGE_PUBLIC_URL=wss://your-domain in .env');
            $this->newLine();
        }

        // Prefer the configured public URL so operators with TLS see the correct
        // wss:// connection command.
        $publicUrl = config('ai-bridge.server.public_url');
        $displayUrl = ! empty($publicUrl) ? $publicUrl : "ws://{$host}:{$port}";

        $this->line('  Bridge clients can connect with:');
        $this->line("  <fg=yellow>npx @tetrixdev/ai-bridge --server={$displayUrl} --token=<JWT></>");
        $this->newLine();
        $this->line('  Generate a token with:');
        $this->line('  <fg=yellow>php artisan ai-bridge:token</> ');
        $this->newLine();
        $this->line('  Press <fg=red>Ctrl+C</> to stop the server.');
        $this->newLine();
    }
}
