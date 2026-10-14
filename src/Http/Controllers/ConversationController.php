<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Events\ConversationCreated;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\AiRequestPayload;
use Tetrix\AiBridge\Tools\ToolRegistry;

/**
 * HTTP API for conversation CRUD + streaming.
 *
 * Every action is scoped through the project-supplied conversations resolver
 * (AiBridgeManager::conversationsQuery) — the package itself owns no ownership
 * concept. Routes are additionally protected by the configured route_middleware.
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly AiBridgeManager $manager,
        private readonly ToolRegistry $toolRegistry,
    ) {}

    /**
     * GET /ai-bridge/conversations — list conversations visible to the request.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 30), 1), 100);

        $conversations = $this->manager->conversationsQuery($request)
            ->latest('updated_at')
            ->paginate($perPage);

        return response()->json($conversations);
    }

    /**
     * POST /ai-bridge/conversations — create a conversation.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'mode' => ['required', 'string', 'in:bridge,byok,managed'],
            'provider' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:128'],
            'system_prompt' => ['nullable', 'string', 'max:'.(int) config('ai-bridge.streaming.max_system_prompt_length', 10000)],
            'connection_id' => ['nullable', 'integer'],
            'working_dir' => ['nullable', 'string', 'max:1024'],
        ]);

        // A supplied connection must be one the request is allowed to see.
        $connection = null;
        if (! empty($validated['connection_id'])) {
            $connection = $this->manager->connectionsQuery($request)
                ->whereKey($validated['connection_id'])->first();
            if ($connection === null) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => 'The selected connection is not available.',
                ], 422);
            }
        }

        // The workspace is chosen once, when the chat starts, because the
        // bridge fixes it for the life of the CLI session and refuses a later
        // turn that names a different one.
        if (! empty($validated['working_dir'])) {
            if ($validated['mode'] !== 'bridge') {
                return $this->badWorkspace('A working directory is only supported in bridge mode.');
            }
            $rejection = $this->rejectUnknownWorkspace($validated['working_dir'], $connection);
            if ($rejection !== null) {
                return $rejection;
            }
        }

        $conversation = Conversation::create([
            'title' => $validated['title'] ?? null,
            'mode' => $validated['mode'],
            'provider' => $validated['provider'] ?? null,
            'model' => $validated['model'] ?? null,
            'system_prompt' => $validated['system_prompt'] ?? null,
            'connection_id' => $validated['connection_id'] ?? null,
            'working_dir' => $validated['working_dir'] ?? null,
            'tools_hash' => $this->toolRegistry->hash(),
        ]);

        // Let the consuming app link the (unlinked) conversation to its owner.
        event(new ConversationCreated($conversation, $request));

        return response()->json($conversation->fresh(), 201);
    }

    /**
     * GET /ai-bridge/conversations/{id} — a conversation with its messages.
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        $conversation = $this->manager->conversationsQuery($request)
            ->whereKey($id)->first();

        if ($conversation === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Conversation not found.'], 404);
        }

        $conversation->load('messages', 'connection');

        return response()->json([
            'conversation' => $conversation,
            'messages' => $conversation->messages,
            // The request_id of an actively-streaming turn for this
            // conversation, or null if none is in flight. The chat UI
            // checks this on conversation open to decide whether to attach
            // an EventSource to /ai-bridge/streams/{rid}/events for replay.
            'streaming_request_id' => $conversation->streaming_request_id,
            // Soft signal: the registered tool set changed since this
            // conversation was created.
            'tools_stale' => $conversation->tools_hash !== null
                && $conversation->tools_hash !== $this->toolRegistry->hash(),
        ]);
    }

    /**
     * DELETE /ai-bridge/conversations/{id}.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $conversation = $this->manager->conversationsQuery($request)
            ->whereKey($id)->first();

        if ($conversation === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Conversation not found.'], 404);
        }

        $conversation->delete(); // messages cascade via FK

        return response()->json(['status' => 'deleted']);
    }

    /**
     * POST /ai-bridge/conversations/{id}/stream — send a message.
     *
     * Returns JSON {status, request_id} immediately. The browser then opens
     * an EventSource against /ai-bridge/streams/{request_id}/events to
     * receive the streamed reply; that endpoint resumes by Last-Event-ID
     * after a refresh or reconnect.
     */
    public function stream(Request $request, int|string $id): JsonResponse
    {
        $conversation = $this->manager->conversationsQuery($request)
            ->whereKey($id)->first();

        if ($conversation === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Conversation not found.'], 404);
        }

        $message = (string) $request->input('message', '');
        if (trim($message) === '') {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'The message field is required and cannot be empty.',
            ], 422);
        }

        // One in-flight turn per conversation. A double-clicked Send (or a
        // second tab racing) would otherwise overwrite streaming_request_id
        // and leave one of the turns orphaned in the buffer. Reject with 409
        // when a turn is genuinely still running; ignore stale pointers
        // (a previous turn that died without clearing the column — buffer
        // says not_found / completed).
        if ($conversation->streaming_request_id !== null) {
            $status = app(StreamStoreContract::class)->status($conversation->streaming_request_id);
            if ($status['status'] === 'streaming') {
                return response()->json([
                    'error' => 'conflict',
                    'message' => 'Another turn is already in flight for this conversation.',
                    'request_id' => $conversation->streaming_request_id,
                ], 409);
            }
        }

        // Same argument as attachments further down: only the bridge path can
        // act on a working directory, so accepting one anywhere else stores a
        // value the conversation displays and no turn ever uses — and the user
        // reads the assistant's "I can't see that repo" as a broken bridge.
        // Checked before anything reads or writes the field.
        if ($request->exists('working_dir') && $conversation->mode !== 'bridge') {
            return $this->badWorkspace('A working directory is only supported in bridge mode.');
        }

        // A workspace may still be chosen on the FIRST message, for apps that
        // create the conversation before the developer has picked one. After
        // that it is fixed: silently switching it would produce a
        // `working_dir_changed` refusal from the bridge mid-chat, which is a
        // confusing way to learn that the directory is part of the session.
        // An explicit empty value clears the workspace and returns the
        // conversation to an ordinary chat. Without this there is no way back:
        // a bridge restarted with a narrower --allow-dir, or a conversation
        // relinked to another connection, would fail the re-check below on
        // every subsequent turn with no recovery short of deleting the
        // conversation.
        //
        // Clearing comes FIRST, before the re-check below. The whole point of
        // being able to clear is to escape a conversation pinned to a directory
        // the bridge no longer allows — and the re-check is exactly what
        // refuses such a conversation. Running it first meant the escape hatch
        // 422'd before it could be used.
        if ($this->isClearingWorkspace($request)) {
            if ($conversation->working_dir !== null) {
                // The session is bound to the old directory, so it goes too —
                // same reasoning as setting one.
                $conversation->fill(['working_dir' => null, 'cli_session_id' => null])->save();
            }
        } elseif ($request->exists('working_dir')) {
            $raw = $request->input('working_dir');
            // `filled()` is trim-based, so "   " would fall through all three
            // branches: not a clear, not a set, 200 and no workspace — while a
            // relative path correctly 422s. Refuse it instead of ignoring it.
            if (is_string($raw) && trim($raw) === '') {
                return $this->badWorkspace(
                    'A working directory must be an absolute path, or an empty string to clear it.'
                );
            }
            // Checked as a type, not cast. `filled()` is true for an array, and
            // `(string) []` raises an ErrorException that lands outside the
            // InvalidArgumentException catch below — a 500 where a 422 belongs.
            if (! is_string($raw)) {
                return $this->badWorkspace('A working directory must be a string.');
            }
            $requested = $raw;
            if (mb_strlen($requested) > 1024) {
                return $this->badWorkspace('A working directory may be at most 1024 characters.');
            }
            if ($conversation->working_dir === null) {
                $conversation->loadMissing('connection');
                $rejection = $this->rejectUnknownWorkspace($requested, $conversation->connection);
                if ($rejection !== null) {
                    return $rejection;
                }
                // Drop the CLI session along with the change.
                //
                // The bridge ties a working directory to a session for that
                // session's life. If turn 1 ran without a directory, the
                // bridge recorded that session against its scratch directory —
                // so sending a workspace on turn 2 with the stored session id
                // is refused as `working_dir_changed`, and so is every turn
                // after it, while the directory can no longer be changed back.
                // The conversation would be dead until the bridge restarted.
                // Starting a fresh session is exactly what the bridge asks the
                // server to do here; history is re-seeded automatically because
                // `cli_session_id` is null.
                $conversation->fill([
                    'working_dir' => $requested,
                    'cli_session_id' => null,
                ])->save();
            } elseif ($conversation->working_dir !== $requested) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => 'This conversation is already working in "'.$conversation->working_dir
                        .'". A conversation cannot change working directory — start a new one.',
                ], 422);
            }
        }

        // Validate the directory this turn will actually send. A workspace can
        // be chosen at create time, when the conversation may not yet be linked
        // to a connection and there is no advertised list to check against, so
        // the check has to happen somewhere the connection is known.
        if ($conversation->working_dir !== null) {
            $conversation->loadMissing('connection');
            $rejection = $this->rejectUnknownWorkspace($conversation->working_dir, $conversation->connection);
            if ($rejection !== null) {
                return $rejection;
            }
        }

        // Optional per-turn provider/model override (e.g. switching mid-conversation).
        //
        // After the workspace branches, not before: those can now return 422,
        // and a rejected turn that had already persisted a model switch would
        // leave the conversation changed by a request that did nothing.
        $this->applyOverrides($request, $conversation);

        // Files attached to THIS message, as ids. Per turn rather than per
        // conversation: the bridge downloads them, tells the model where they
        // landed, and deletes them when the turn ends.
        //
        // Ids, not URLs — the server builds the URL from its own route, so a
        // browser cannot nominate what the bridge fetches. See
        // AiBridgeManager::buildAttachmentRefs().
        $options = [];

        // Optional thinking level for THIS turn (Claude's `--effort`). Per turn, not stored
        // on the conversation; absent or empty sends nothing. Which words a machine honours
        // is the bridge's to say (ConnectionStatus::for()['effort_levels']), and it ignores
        // any other, so only the shape is checked here.
        $effort = $request->input('effort');
        if ($effort !== null && $effort !== '') {
            if (AiRequestPayload::normaliseEffort($effort) === null) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => 'effort must be a string of at most '.AiRequestPayload::MAX_EFFORT_LENGTH.' characters.',
                ], 422);
            }
            $options['effort'] = $effort;
        }

        $attachmentIds = $request->input('attachments');

        if (is_array($attachmentIds) && $attachmentIds !== [] && $conversation->mode !== 'bridge') {
            // Only the bridge path carries attachments; ChatCompletionsStream
            // has no notion of them. Accepting them here would resolve every
            // file, hash it, answer 200 — and the model would never see any of
            // it, which reads as the assistant ignoring a file the user
            // watched themselves attach.
            return response()->json([
                'error' => 'validation_error',
                'message' => 'Attachments are only supported in bridge mode.',
            ], 422);
        }

        try {
            if (is_array($attachmentIds) && $attachmentIds !== []) {
                $options['attachments'] = $this->manager->buildAttachmentRefs(
                    $attachmentIds,
                    // The same identifier the bridge's own token carries, so
                    // the scoping here and the scoping on the fetch agree.
                    $this->manager->bridgeUserIdFor($conversation),
                );
            }

            $requestId = $this->manager->startConversationStream($conversation, $message, $options);
        } catch (\InvalidArgumentException $e) {
            // An id that does not resolve, or an attachment missing a field the
            // bridge needs. A 422 naming the problem beats a turn that starts
            // and then fails somewhere the user cannot see.
            return response()->json([
                'error' => 'validation_error',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'started',
            'request_id' => $requestId,
        ]);
    }

    /**
     * Is this request explicitly clearing the conversation's workspace?
     *
     * Only an empty STRING counts. Accepting `null` as well would be friendlier
     * right up until a client does `JSON.stringify({message, working_dir:
     * selectedDir})` with nothing selected — which silently unpins the
     * workspace and throws away the CLI session, with a 200 and nothing in the
     * log. An empty string is a thing somebody typed; a null is a thing that
     * happened.
     */
    private function isClearingWorkspace(Request $request): bool
    {
        return $request->input('working_dir') === '';
    }

    /**
     * Refuse a working directory the connected bridge has not advertised.
     *
     * The bridge is the authority — it refuses anything outside its own
     * `--allow-dir` roots whatever the server sends — so this is a courtesy,
     * not the control. But it is a worthwhile one: without it a mistyped path
     * is only discovered after the turn starts, arriving as a stream error in
     * the middle of a chat rather than as a validation failure on the request
     * that caused it.
     *
     * When the bridge advertised no workspaces the check is skipped rather
     * than failed: it may simply be between reconnects, and the bridge will
     * give the authoritative answer either way.
     */
    private function rejectUnknownWorkspace(string $workingDir, ?\Tetrix\AiBridge\Models\Connection $connection): ?JsonResponse
    {
        // Shape checks first, and they apply whether or not the bridge has
        // advertised anything. A `..` segment is refused here rather than left
        // to the bridge: the bridge does resolve it correctly (it realpaths
        // before comparing), but the answer then arrives as a stream error in
        // the middle of a chat instead of as a 422 on the request that caused
        // it, which is the entire reason this check exists.
        if ($workingDir === '' || str_contains($workingDir, "\0") || ! str_starts_with($workingDir, '/')) {
            return $this->badWorkspace('A working directory must be an absolute path.');
        }

        foreach (explode('/', $workingDir) as $segment) {
            if ($segment === '..') {
                return $this->badWorkspace(
                    'A working directory must not contain ".." — give the resolved path.'
                );
            }
        }

        $workspaces = $connection?->last_workspaces ?? [];
        if ($workspaces === []) {
            // The bridge may simply be between reconnects. It gives the
            // authoritative answer either way, so refusing here would only ever
            // be wrong in one direction.
            return null;
        }

        // Trailing slashes are normalised on BOTH sides: an advertised root of
        // "/repos/" must still match the directory "/repos", and "/repos" must
        // not match the sibling "/repos-other".
        $candidate = rtrim($workingDir, '/');

        foreach ($workspaces as $workspace) {
            $root = is_array($workspace) ? ($workspace['path'] ?? null) : null;
            if (! is_string($root) || $root === '') {
                continue;
            }
            $root = rtrim($root, '/');
            if ($candidate === $root || str_starts_with($candidate, $root.'/')) {
                return null;
            }
        }

        return $this->badWorkspace(
            'That working directory is not one this bridge allows. '
            .'Choose one of the workspaces the connection advertises.'
        );
    }

    /** A 422 naming a working directory the request cannot have. */
    private function badWorkspace(string $message): JsonResponse
    {
        return response()->json(['error' => 'validation_error', 'message' => $message], 422);
    }

    /**
     * Apply an optional per-turn provider/model switch to the conversation.
     */
    private function applyOverrides(Request $request, Conversation $conversation): void
    {
        $dirty = [];

        if ($request->filled('provider') && $request->input('provider') !== $conversation->provider) {
            $dirty['provider'] = (string) $request->input('provider');
        }
        if ($request->filled('model') && $request->input('model') !== $conversation->model) {
            $dirty['model'] = (string) $request->input('model');
        }

        if ($dirty !== []) {
            $conversation->fill($dirty)->save();
        }
    }
}
