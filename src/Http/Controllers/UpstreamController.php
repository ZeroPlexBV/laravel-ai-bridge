<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Upstreams\McpRequestFailed;
use Tetrix\AiBridge\Upstreams\UpstreamClient;

/**
 * JSON API for a bridge's upstream MCP servers, next to the connection routes and behind
 * the same middleware. Every route resolves the connection through the application's
 * connections resolver, so a person reaches only their own.
 */
class UpstreamController extends Controller
{
    public function __construct(
        private readonly AiBridgeManager $manager,
        private readonly UpstreamClient $upstreams,
    ) {}

    /** GET /ai-bridge/connections/{id}/upstreams — status per upstream and pending sign-ins. */
    public function index(Request $request, int|string $id): JsonResponse
    {
        $connection = $this->connection($request, $id);
        if ($connection === null) {
            return $this->notFound();
        }

        return response()->json(['connection_id' => $connection->id] + $this->upstreams->status($connection));
    }

    /** GET /ai-bridge/connections/{id}/upstreams/pending — sign-ins waiting on the person. */
    public function pending(Request $request, int|string $id): JsonResponse
    {
        $connection = $this->connection($request, $id);
        if ($connection === null) {
            return $this->notFound();
        }

        return response()->json(['pending' => $this->upstreams->pending($connection)]);
    }

    /**
     * POST /ai-bridge/connections/{id}/upstreams/{server}/sign-in — start a sign-in.
     *
     * 200 with `{ok: true, authorization_url, expires_at, reason}` to open in the browser,
     * or 409/502/504 with `{ok: false, code, error}`.
     */
    public function signIn(Request $request, int|string $id, string $server): JsonResponse
    {
        $connection = $this->connection($request, $id);
        if ($connection === null) {
            return $this->notFound();
        }

        $answer = $this->upstreams->startSignIn($connection, $server);

        return response()->json($answer, ($answer['ok'] ?? false) === true ? 200 : self::statusFor($answer['code'] ?? null));
    }

    /** DELETE /ai-bridge/connections/{id}/upstreams/{server}/sign-in — forget the upstream's tokens. */
    public function forget(Request $request, int|string $id, string $server): JsonResponse
    {
        $connection = $this->connection($request, $id);
        if ($connection === null) {
            return $this->notFound();
        }

        $sent = $this->upstreams->forget($connection, $server);

        return response()->json(['sent' => $sent], $sent ? 200 : 502);
    }

    /**
     * POST /ai-bridge/connections/{id}/mcp — relay a view's request (`{server, method, params}`).
     *
     * 200 `{result}`, or `{error, code}` with 400 (refused here or by the bridge), 404
     * (no bridge connected), 409 (the bridge has no mcp_apps), 502 (the upstream failed)
     * or 504 (timeout).
     */
    public function mcp(Request $request, int|string $id): JsonResponse
    {
        $connection = $this->connection($request, $id);
        if ($connection === null) {
            return $this->notFound();
        }

        $server = $request->input('server');
        $method = $request->input('method');
        $params = $request->input('params', []);

        if (! is_string($server) || ! is_string($method) || ! is_array($params)) {
            return response()->json(['code' => 'invalid_request', 'error' => 'Body must be {server: string, method: string, params: object}.'], 400);
        }

        try {
            return response()->json(['result' => $this->upstreams->mcpRequest($connection, $server, $method, $params)]);
        } catch (McpRequestFailed $e) {
            return response()->json(['code' => $e->errorCode, 'error' => $e->getMessage()], self::statusFor($e->errorCode));
        }
    }

    private static function statusFor(mixed $code): int
    {
        return match ($code) {
            'invalid_request', 'unsupported', 'refused', 'unknown_server', 'not_oauth' => 400,
            'not_connected' => 404,
            'mcp_apps_unsupported', 'mcp_upstream_auth_unsupported' => 409,
            'timeout', 'no_answer' => 504,
            default => 502,
        };
    }

    private function connection(Request $request, int|string $id): ?Connection
    {
        return $this->manager->connectionsQuery($request)->whereKey($id)->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found', 'message' => 'Connection not found.'], 404);
    }
}
