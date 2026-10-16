<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Enums\ProviderMode;

/**
 * Conversation-less SSE streaming endpoint.
 *
 * Provided for callers that want a one-shot AI response without persisting
 * a conversation. The mainstream chat path is the conversation-based one in
 * {@see ConversationController::stream()}; that endpoint hands back a
 * `request_id` the browser tails over the resumable
 * {@see StreamEventsController}.
 */
class StreamController extends Controller
{
    public function __construct(
        private readonly AiBridgeManager $manager,
    ) {}

    /**
     * SSE streaming endpoint.
     *
     * POST /ai-bridge/stream/sse
     *
     * Accepts: conversation_id, message, system_prompt, options
     * Returns: text/event-stream response with normalized AI events.
     *
     * Each SSE line is: data: {"event": "...", "data": {...}}
     * Stream ends with: data: [DONE]
     */
    public function sse(Request $request): StreamedResponse|JsonResponse
    {
        $prepared = $this->prepareRequestOptions($request);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        [$conversationId, $message, $options] = $prepared;

        return $this->manager->streamToResponse($conversationId, $message, $options);
    }

    /**
     * Validate and prepare request options shared with sse().
     *
     * Returns either a [conversationId, message, options] array on success,
     * or a JsonResponse on validation failure.
     *
     * @return array{string, string, array<string, mixed>}|JsonResponse
     */
    private function prepareRequestOptions(Request $request): array|JsonResponse
    {
        $message = $request->input('message', '');
        if (empty(trim($message))) {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'The message field is required and cannot be empty.',
            ], 422);
        }

        $conversationId = $request->input('conversation_id', 'conv-' . \Illuminate\Support\Str::uuid());

        $isManagedMode = $this->manager->mode() === ProviderMode::Managed;
        $options = $this->sanitizeOptions($request);

        // Detect malformed JSON in the options field (flag set by sanitizeOptions()).
        if (isset($options['__malformed_options_json'])) {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'The options field could not be parsed as JSON. Ensure it is valid JSON.',
            ], 422);
        }

        // Only allow per-request overrides in non-managed mode.
        // In managed mode, the app controls AI behavior and cost.
        if (! $isManagedMode) {
            $systemPrompt = $request->input('system_prompt', '');
            if (! empty($systemPrompt)) {
                // Enforce a maximum length to prevent excessively large prompts
                // from consuming disproportionate API tokens.
                $maxSystemPromptLength = (int) config('ai-bridge.streaming.max_system_prompt_length', 10000);
                if (mb_strlen($systemPrompt) > $maxSystemPromptLength) {
                    return response()->json([
                        'error' => 'validation_error',
                        'message' => "The system_prompt field must not exceed {$maxSystemPromptLength} characters.",
                    ], 422);
                }
                $options['system_prompt'] = $systemPrompt;
            }

            // Validate against the configured model allowlist when non-empty;
            // an empty allowlist means any model is permitted.
            $allowedModels = config('ai-bridge.chat_completions.allowed_models', []);

            if ($request->has('model')) {
                $options['model'] = $request->input('model');
            }

            // A 'model' value may also arrive inside the options object — validate
            // that path too so the allowlist cannot be bypassed via options.
            if (! empty($allowedModels) && isset($options['model'])
                && ! in_array($options['model'], (array) $allowedModels, true)) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => 'The requested model is not permitted. Allowed models: '.implode(', ', (array) $allowedModels).'.',
                ], 422);
            }
        }

        return [$conversationId, $message, $options];
    }

    /**
     * Parse and sanitize the options from the request.
     *
     * Uses a whitelist approach: only explicitly permitted keys are accepted.
     * This is safer than a blacklist because new sensitive options added in the
     * future are blocked by default unless the developer explicitly permits them.
     *
     * Permitted client-controllable options (non-managed mode):
     *   temperature, max_tokens, model, effort, messages, stream_options
     *
     * In managed mode, the application controls AI behavior and cost, so only
     * non-cost, non-behavior options pass through (currently none beyond the base set).
     *
     * @return array<string, mixed>
     */
    private function sanitizeOptions(Request $request): array
    {
        $raw = $request->input('options', []);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                // Malformed JSON in options field — fail fast rather than
                // silently discarding the options.
                return ['__malformed_options_json' => true];
            }
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            $raw = [];
        }

        if ($this->manager->mode() === ProviderMode::Managed) {
            // In managed mode, the app controls AI behavior and bears the cost.
            // No client-supplied options pass through.
            return [];
        }

        // Whitelist of client-permitted option keys. Any key not listed here is
        // silently dropped.
        $allowed = ['temperature', 'max_tokens', 'model', 'effort', 'messages', 'stream_options'];

        return array_intersect_key($raw, array_flip($allowed));
    }
}
