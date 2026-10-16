<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Upstreams;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Models\Message;

/**
 * Which upstream server a call's view belongs to, by connection and call id, so a view's
 * request (AiBridge::mcpRequest() with a tool_call_id) can be held to the server of the
 * view the person actually saw.
 *
 * Written by the serve process when it keeps a bridge-sent `tool_result.ui` (in the cache
 * store pending sign-ins use, for `ai-bridge.upstream_oauth.view_ttl` seconds); read back
 * from there, or, once that has expired, from the recorded blocks of the connection's
 * recent conversations.
 */
final class UpstreamViews
{
    private const PREFIX = 'ai-bridge:upstream-view:';

    /** How many recent assistant messages the fallback looks through. */
    private const SCAN_MESSAGES = 200;

    public static function remember(int $connectionId, string $toolCallId, string $server): void
    {
        try {
            PendingSignIns::cache()->put(self::key($connectionId, $toolCallId), ['server' => $server], (int) config('ai-bridge.upstream_oauth.view_ttl', 604800));
        } catch (\Throwable $e) {
            Log::warning('AI Bridge: could not remember an upstream view', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The server of the view recorded for this call on this connection, or null when none is.
     */
    public static function serverOf(int $connectionId, string $toolCallId): ?string
    {
        if ($toolCallId === '' || strlen($toolCallId) > 256) {
            return null;
        }

        $cached = PendingSignIns::cache()->get(self::key($connectionId, $toolCallId));
        if (is_array($cached) && is_string($cached['server'] ?? null)) {
            return $cached['server'];
        }

        $messages = Message::query()
            ->whereIn('conversation_id', Conversation::query()->where('connection_id', $connectionId)->select('id'))
            ->where('role', 'assistant')
            ->latest('id')
            ->limit(self::SCAN_MESSAGES)
            ->get(['id', 'blocks']);

        foreach ($messages as $message) {
            foreach ($message->blocks ?? [] as $block) {
                $ui = is_array($block) ? ($block['ui'] ?? null) : null;
                if (($block['tool_call_id'] ?? null) === $toolCallId && is_array($ui)
                    && is_string($ui['server'] ?? null) && ($ui['connection_id'] ?? null) === $connectionId) {
                    return $ui['server'];
                }
            }
        }

        return null;
    }

    private static function key(int $connectionId, string $toolCallId): string
    {
        return self::PREFIX.$connectionId.':'.hash('sha256', $toolCallId);
    }
}
