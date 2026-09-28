<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Active Mode
    |--------------------------------------------------------------------------
    |
    | Determines how AI responses are generated.
    |
    | - 'bridge': Responses come from a CLI bridge connected via WebSocket.
    | - 'byok':   Responses come from the Chat Completions API using a
    |             user-provided API key (Bring Your Own Key).
    | - 'managed': Responses come from the Chat Completions API using an
    |              application-managed API key.
    |
    */

    'mode' => env('AI_BRIDGE_MODE', 'byok'),

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to all AI Bridge HTTP routes. Defaults to ['auth']
    | to ensure routes are protected out of the box. Customize this to use
    | your app's auth guard (e.g. ['auth:sanctum'], ['auth:api']).
    |
    | Set to an empty array to disable default auth middleware (not recommended).
    |
    */

    'route_middleware' => ['auth'],

    /*
    |--------------------------------------------------------------------------
    | JWT Token Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for JWT tokens used to authenticate bridge connections.
    |
    */

    'token' => [
        'secret' => env('AI_BRIDGE_TOKEN_SECRET'),
        'ttl' => env('AI_BRIDGE_TOKEN_TTL', 86400), // 24 hours in seconds

        // TTL for CLI bridge connection tokens. These are long-lived: a bridge
        // is a semi-permanent connection, and the server tops the token up
        // (re-issues it once past half its life) so a bridge that keeps
        // connecting — or stays connected for the full window — never expires.
        'bridge_ttl' => env('AI_BRIDGE_BRIDGE_TOKEN_TTL', 2592000), // 30 days in seconds

        // How often the bridge server sweeps connected bridges to re-issue
        // tokens that are past half their life. Far slower than the heartbeat —
        // it only needs to fire well within bridge_ttl / 2.
        'refresh_check_interval' => env('AI_BRIDGE_TOKEN_REFRESH_INTERVAL', 43200), // 12 hours

        // Audience claim that scopes tokens to this application instance, so
        // tokens issued for one deployment are rejected by another that shares
        // the same secret. Defaults to config('app.url') when left null.
        'audience' => env('AI_BRIDGE_TOKEN_AUDIENCE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WebSocket Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the WebSocket bridge connection.
    |
    */

    'websocket' => [
        'heartbeat_interval' => 30,   // seconds between ping/pong

        /*
         * How long a turn may produce NOTHING before the bridge presumes the
         * CLI is wedged and stops it. This is the bound that kills, because
         * silence is the only measure that separates stuck from busy: an
         * assistant reading a codebase, waiting on a build or running a test
         * suite emits nothing for minutes and is working the whole time.
         *
         * 0 disables it, leaving the turn bounded only by request_timeout.
         */
        'silence_timeout' => 900,

        /*
         * A wall-clock ceiling for one turn, as a backstop.
         *
         * Was 300, which killed turns that were visibly working — a research
         * turn that had streamed 370 events died at exactly 300 seconds,
         * mid-work. A wall clock cannot tell a stuck CLI from a busy one, so
         * it is no longer the bound doing the work; silence_timeout is.
         *
         * 0 means no ceiling at all, for a server that bounds turns itself.
         */
        'request_timeout' => 86400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat Completions Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the Chat Completions API (used in BYOK and managed modes).
    |
    */

    'chat_completions' => [
        'endpoint' => env('AI_BRIDGE_ENDPOINT'),       // e.g. https://api.openai.com
        'api_key' => env('AI_BRIDGE_API_KEY'),          // User's key (BYOK) or app's key (managed)
        'model' => env('AI_BRIDGE_MODEL'),              // e.g. gpt-4o
        'max_tokens' => env('AI_BRIDGE_MAX_TOKENS', 4096),
        'stream_timeout' => env('AI_BRIDGE_STREAM_TIMEOUT', 300), // seconds before stream HTTP timeout

        // Optional allowlist of permitted model values for client-supplied 'model' overrides.
        // When non-empty, requests with a model not in this list are rejected with HTTP 422.
        // Leave empty to allow any model (not recommended in managed or shared deployments).
        // Example: ['gpt-4o', 'gpt-4o-mini']
        'allowed_models' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bridge WebSocket Server Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the dedicated WebSocket server that handles CLI bridge
    | connections. Runs on its own port and speaks the AI Bridge Protocol —
    | separate from any other realtime infrastructure your app may use.
    |
    */

    'server' => [
        'host' => env('AI_BRIDGE_SERVER_HOST', '127.0.0.1'),
        'port' => env('AI_BRIDGE_SERVER_PORT', 8085),
        'relay_timeout' => env('AI_BRIDGE_RELAY_TIMEOUT', 5), // seconds for internal HTTP relay

        // How long the serve process holds a usage question open before answering that the
        // bridge did not reply. Longer than relay_timeout because this one waits on a round
        // trip to the machine AND the machine's own call to its vendor, not just on us.
        'usage_timeout' => env('AI_BRIDGE_USAGE_TIMEOUT', 12), // seconds

        // How long the serve process waits for the bridge to accept or refuse a message sent
        // into a running turn (AiBridge::sendTurnInput) before answering `no_answer`. The
        // bridge answers at once — it only writes to the CLI's input — so this is short.
        'turn_input_timeout' => env('AI_BRIDGE_TURN_INPUT_TIMEOUT', 5), // seconds

        // URL for internal relay requests (PHP-FPM → bridge server communication).
        // Override to use HTTPS if the bridge server is behind a TLS-terminating proxy.
        // Security: When running the bridge server on a separate host, use HTTPS to
        // protect internal relay tokens in transit.
        'relay_url' => env('AI_BRIDGE_RELAY_URL'),

        // The public-facing WebSocket URL returned in the token response.
        // Set this when the bridge server is behind a TLS-terminating reverse proxy
        // (e.g. nginx) so clients receive a wss:// URL instead of the internal ws:// address.
        // If null (default), the token endpoint returns ws://{server.host}:{server.port}.
        // Example: AI_BRIDGE_PUBLIC_URL=wss://bridge.example.com
        'public_url' => env('AI_BRIDGE_PUBLIC_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stream Event Buffer
    |--------------------------------------------------------------------------
    |
    | Per-turn buffer of streaming AI events. The serve process appends events
    | here as they arrive from the bridge; the browser tails the buffer over
    | SSE and resumes by Last-Event-ID after a refresh or reconnect. The DB
    | is still the archive — the final assistant message is written once at
    | the end of the turn, separate from this buffer.
    |
    | 'default' picks the driver. The package ships with 'redis' (production
    | default) and 'array' (in-memory, tests only). Apps register their own
    | with StreamStore::extend('foo', fn ($app) => new MyStore()).
    |
    | Redis driver settings:
    |
    | - 'connection': the Redis connection from config/database.php to use.
    |   null = the application's default Redis connection.
    | - 'prefix': key prefix; full key shape is "{prefix}:{rid}:events" etc.
    | - 'ttl_streaming': TTL while a turn is live. Refreshed on every event.
    | - 'ttl_completed': TTL once a turn has terminated. Bounds how long a
    |   recent page-load can replay it before the buffer expires.
    |
    */

    'stream_store' => [
        'default' => env('AI_BRIDGE_STREAM_STORE', 'redis'),

        'redis' => [
            'connection' => env('AI_BRIDGE_STREAM_REDIS_CONNECTION'),
            'prefix' => env('AI_BRIDGE_STREAM_REDIS_PREFIX', 'ai-bridge:stream'),
            'ttl_streaming' => (int) env('AI_BRIDGE_STREAM_TTL_STREAMING', 3600),
            'ttl_completed' => (int) env('AI_BRIDGE_STREAM_TTL_COMPLETED', 1800),
        ],

        // How often the SSE tail endpoint polls the store for new events
        // while a turn is streaming. Lower = snappier; higher = less load.
        // 100ms is what pocket-dev uses; safe under reasonable concurrency.
        'poll_interval_ms' => (int) env('AI_BRIDGE_STREAM_POLL_MS', 100),

        // Keepalive cadence on the SSE tail — must beat any intermediate
        // proxy/load-balancer idle timeout. Default 30s is safe for nginx
        // defaults (60s proxy_read_timeout).
        'keepalive_interval_s' => (int) env('AI_BRIDGE_STREAM_KEEPALIVE_S', 30),

        // Maximum lifetime of one SSE tail connection in seconds. After
        // this, the server closes the response and the browser reconnects
        // (free, via EventSource). Keeps PHP-FPM workers cycling.
        'max_connection_s' => (int) env('AI_BRIDGE_STREAM_MAX_CONNECTION_S', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | The package logs the bridge relay path (a message being relayed to a CLI
    | bridge, stream events arriving back into the buffer, terminal done/error).
    | This is the first place to look when the chat UI hangs on "Thinking".
    |
    | - 'channel': the log channel these messages go to. Leave null to use the
    |   host app's default channel. Point it at a dedicated channel (e.g. a
    |   daily channel with its own retention) to keep bridge logs separate.
    |   If the named channel is not defined, the package falls back to the
    |   default channel rather than throwing.
    | - 'verbose': when true, also log per-event detail (every stream event,
    |   relayed payloads) at debug level. Useful in development; noisy in
    |   production.
    |
    */

    'logging' => [
        'channel' => env('AI_BRIDGE_LOG_CHANNEL'),
        'verbose' => env('AI_BRIDGE_LOG_VERBOSE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | CLI Bridge Command
    |--------------------------------------------------------------------------
    |
    | The "Add a CLI bridge" flow hands the user a command to start their local
    | bridge. By default this is `npx @tetrixdev/ai-bridge@latest`, which runs
    | the published npm package.
    |
    | For local development of the bridge CLI itself, set 'local_path' to the
    | absolute path of an ai-bridge repo checkout (on the machine that will run
    | the command). When APP_ENV=local AND this is set, the generated command
    | runs that checkout's build directly — `node <path>/dist/cli.js` — so CLI
    | changes can be tested without publishing to npm. Build the checkout first
    | (`npm run build`, or `npm run dev` to rebuild on change).
    |
    */

    'cli' => [
        'local_path' => env('AI_BRIDGE_CLI_LOCAL_PATH'),

        /*
        |----------------------------------------------------------------------
        | CLI isolation posture
        |----------------------------------------------------------------------
        |
        | Controls how much the local CLI environment is allowed to influence
        | behaviour. The value is forwarded as `cli_isolation` in the welcome
        | message; the bridge translates it into a different per-provider flag
        | set:
        |
        |   isolated  (default, safe)
        |     - Server-declared tools are reached through the bridge's local
        |       MCP server only. The CLI's built-in shell / edit / web tools
        |       require approval and have no interactive approver in headless
        |       mode, so the model effectively cannot use them.
        |     - Claude runs with `--bare`: no hooks, no auto-memory, no
        |       keychain reads, no CLAUDE.md auto-discovery, no plugin sync.
        |     - Other MCP servers configured on the operator's machine are
        |       ignored (`--strict-mcp-config` for Claude; equivalents where
        |       supported).
        |     - When the server doesn't send a `system_prompt`, the bridge
        |       injects a neutral fallback so the CLI's built-in default never
        |       seeps through.
        |     - Residual leak surface: user-level ~/.codex/AGENTS.md and
        |       ~/.gemini/GEMINI.md (and Codex/Gemini skills) still load —
        |       closing that requires CODEX_HOME / GEMINI_HOME redirection
        |       with auth symlinking (Layer B; see the ai-bridge repo).
        |     - Use this for any deployment where end users send chat messages
        |       to the bridge (e.g. DungeonMeister players).
        |
        |   workspace  (the posture for a chat that edits code)
        |     - Everything `isolated` does to keep the OPERATOR's environment
        |       out still applies: `--strict-mcp-config`, no other MCP servers,
        |       the neutral fallback system prompt.
        |     - What changes is what the CLI may DO: its built-in file and
        |       shell tools are enabled, so it can read a checkout, edit it,
        |       run the tests and commit. That is the whole point of driving a
        |       local CLI rather than calling an API.
        |     - Per provider: Claude keeps `--strict-mcp-config` and adds
        |       `--permission-mode bypassPermissions` (in headless `-p` mode
        |       `acceptEdits` still stalls on the first shell command, and
        |       running the tests IS a shell command); Codex gets
        |       `sandbox_mode=workspace-write`, deliberately not
        |       `danger-full-access`; Gemini gets `--yolo`, which is the only
        |       lever it offers, so Gemini in this mode is broader than the
        |       other two.
        |     - Needs a bridge started with `--allow-dir` and a `working_dir`
        |       on the request. Without both, the CLI just works in an empty
        |       scratch directory and there is nothing for it to edit.
        |     - THIS IS NOT A SANDBOX. Once the CLI has a shell, `cd ..` and
        |       `~/.ssh` are one command away, whatever directory it started
        |       in. What bounds it is that `--allow-dir` is opt-in and empty by
        |       default, and that the connection token is per person and
        |       revocable. Only point a bridge at a server you would give a
        |       shell to.
        |
        |   native  (legacy, unsafe with untrusted input)
        |     - The bridge ALSO passes the CLI-specific bypass flags
        |       (`bypassPermissions` / `danger-full-access` / `--yolo`), and
        |       leaves the CLI's full local environment intact: user CLAUDE.md
        |       / AGENTS.md / GEMINI.md, skills, hooks, configured MCP
        |       servers, plugins, and the CLI's default system prompt all
        |       load normally.
        |     - Only appropriate when the bridge operator IS the user (e.g.
        |       a developer running the bridge against their own repo). A
        |       chat message from a third party in this mode can drive an
        |       autonomous coding agent across the operator's disk.
        |
        | Default: isolated.
        |
        */
        'isolation' => env('AI_BRIDGE_CLI_ISOLATION', 'isolated'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming Options
    |--------------------------------------------------------------------------
    */

    'streaming' => [
        // When true, thinking/reasoning block events are suppressed from SSE
        // outputs and the per-turn buffer. Defaults to TRUE to prevent AI chain-of-thought reasoning
        // from being accidentally exposed to end users — set to false only when you
        // intentionally want to display AI reasoning in your UI (e.g. a debug view).
        'suppress_thinking_blocks' => env('AI_BRIDGE_SUPPRESS_THINKING', true),

        // Maximum length of the user-supplied system_prompt field in non-managed mode.
        // Prevents excessively large prompts from consuming disproportionate API tokens.
        'max_system_prompt_length' => env('AI_BRIDGE_MAX_SYSTEM_PROMPT_LENGTH', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation Persistence
    |--------------------------------------------------------------------------
    |
    | The package always persists conversations and messages to the database
    | (tables: ai_bridge_conversations, ai_bridge_messages, ai_bridge_connections),
    | using the application's default database connection. There is no opt-out —
    | a consuming app that does not want a conversation can simply not create one.
    |
    | Ownership/authorization is NOT handled here. The package tables are
    | unlinked; the consuming app scopes access by registering query resolvers
    | via the AiBridge facade in a service provider's boot() method:
    |
    |   AiBridge::resolveConversationsUsing(
    |       fn ($request) => $mySession->conversations()->getQuery()
    |   );
    |   AiBridge::resolveConnectionsUsing(
    |       fn ($request) => $mySession->connections()->getQuery()
    |   );
    |
    */

    'persistence' => [
        // Persist a partial assistant message when a stream errors or is
        // cancelled mid-response. The row is flagged incomplete=true.
        'persist_partial_on_error' => env('AI_BRIDGE_PERSIST_PARTIAL', true),
    ],
];
