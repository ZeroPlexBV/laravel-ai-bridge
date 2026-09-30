<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Protocol;

use InvalidArgumentException;

/**
 * The one place an `ai_request` frame is shaped.
 *
 * There are two paths that send one — BridgeStream::buildRequestBody() for a
 * long-running process holding the WebSocket, and
 * BridgeWebSocketServer::apiRequest() for the PHP-FPM relay — and for a long
 * time they were two hand-maintained copies with a comment on each asking the
 * next person to keep them in sync. A field added to one and forgotten in the
 * other does not fail: it works under Octane and silently does nothing under
 * PHP-FPM, which is the deployment most apps actually run.
 *
 * So both now normalise their own inputs and call this. The comment asking for
 * vigilance is replaced by a function, and a test asserts the two agree.
 */
final class AiRequestPayload
{
    /** The `bridge_prompt.mode` values the bridge accepts (0.12.0+). */
    public const BRIDGE_PROMPT_MODES = ['default', 'off', 'append', 'replace'];

    /** The bridge's cap on `bridge_prompt.text`, in bytes (MAX_BRIDGE_PROMPT_BYTES). */
    public const MAX_BRIDGE_PROMPT_BYTES = 8192;

    /**
     * Build the wire payload.
     *
     * Optional fields are OMITTED rather than sent empty. The bridge reads
     * every one of them as "absent means the default", and an explicit empty
     * value is only ever noise on the wire — but more importantly, keeping one
     * convention is what lets the two builders be compared at all.
     *
     * @param  array{
     *     request_id: string,
     *     conversation_id?: string|int|null,
     *     provider?: string|null,
     *     message: string,
     *     system_prompt?: string|null,
     *     options?: array<string, mixed>|null,
     *     cli_session_id?: string|null,
     *     history?: array<int, mixed>|null,
     *     tools?: array<int, mixed>|null,
     *     working_dir?: string|null,
     *     attachments?: array<int, array<string, mixed>>|null,
     *     bridge_prompt?: array{mode?: string|null, text?: string|null}|null,
     * }  $input
     * @return array<string, mixed>
     */
    public static function build(array $input): array
    {
        $payload = [
            'type' => MessageTypes::AI_REQUEST,
            'request_id' => (string) $input['request_id'],
            'conversation_id' => (string) ($input['conversation_id'] ?? ''),
            'provider' => (string) ($input['provider'] ?? ''),
            'message' => (string) $input['message'],
        ];

        if (isset($input['system_prompt'])) {
            $payload['system_prompt'] = $input['system_prompt'];
        }

        // Coerced rather than trusted. This builder is reached from the relay
        // endpoint with a caller-supplied JSON body, and a bare
        // `array_filter($notAnArray)` raises a TypeError — which escapes the
        // ReactPHP data callback and takes the whole serve process down,
        // dropping every connected bridge rather than failing one request.
        $options = array_filter(
            is_array($input['options'] ?? null) ? $input['options'] : [],
            static fn ($value) => $value !== null,
        );
        // Opt-in, and only ever `true` on the wire. It switches the turn into
        // a mode where the CLI's input stays open and background tasks are on,
        // so a stray truthy value ("1", "yes") from a relay body must not be
        // what turns it on, and `false` means the same as absent.
        if (array_key_exists('accepts_input', $options) && $options['accepts_input'] !== true) {
            unset($options['accepts_input']);
        }
        if (! empty($options)) {
            $payload['options'] = $options;
        }

        // Always sent, including as null: the server owns the conversation →
        // CLI session mapping, so the bridge never has to guess whether a
        // conversation is new.
        $cliSessionId = $input['cli_session_id'] ?? null;
        $payload['cli_session_id'] = $cliSessionId;

        // History seeds a FRESH session only. A resumed CLI session already
        // holds its own context, so re-sending it there is wasted bytes the
        // bridge discards anyway.
        if ($cliSessionId === null && is_array($input['history'] ?? null) && $input['history'] !== []) {
            $payload['history'] = $input['history'];
        }

        if (is_array($input['tools'] ?? null) && $input['tools'] !== []) {
            $payload['tools'] = $input['tools'];
        }

        $workingDir = $input['working_dir'] ?? null;
        if (is_string($workingDir) && $workingDir !== '') {
            $payload['working_dir'] = $workingDir;
        }

        $rawAttachments = $input['attachments'] ?? [];
        if (! is_array($rawAttachments)) {
            throw new InvalidArgumentException('"attachments" must be an array.');
        }
        $attachments = self::normaliseAttachments($rawAttachments);
        if (! empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        $bridgePrompt = self::normaliseBridgePrompt($input['bridge_prompt'] ?? null);
        if ($bridgePrompt !== null) {
            $payload['bridge_prompt'] = $bridgePrompt;
        }

        return $payload;
    }

    /**
     * The bridge's own prompt addendum, as the request wants it handled.
     *
     * Beside `system_prompt`, never instead of it. For Claude, `system_prompt`
     * goes on `--system-prompt` and replaces the CLI's default prompt; the
     * bridge's addendum (how the session runs: one process per turn, what that
     * means for background work, and on an `accepts_input` turn how messages
     * arrive mid-turn) goes on `--append-system-prompt`. `bridge_prompt`
     * decides what that second part is:
     *
     *  - `default` (or absent): the bridge's addendum. No text allowed.
     *  - `off`: nothing appended. No text allowed.
     *  - `append`: the bridge's addendum, then `text`. Text required.
     *  - `replace`: `text` instead of the addendum. Text required.
     *
     * Validated here with the bridge's own rules (ai-bridge providers/env.ts,
     * validateBridgePrompt) so a contradictory spec fails at the caller rather
     * than as a `bridge_prompt_invalid` refusal of the whole turn. Understood by
     * bridges from 0.12.0; an older bridge ignores the field. `default` is sent
     * as absent: it is what absent means, on every bridge version.
     *
     * @return array{mode: string, text?: string}|null
     */
    private static function normaliseBridgePrompt(mixed $spec): ?array
    {
        if ($spec === null) {
            return null;
        }
        if (is_string($spec)) {
            $spec = ['mode' => $spec];
        }
        if (! is_array($spec)) {
            throw new InvalidArgumentException('"bridge_prompt" must be an object with "mode" and optional "text".');
        }

        $mode = $spec['mode'] ?? 'default';
        if (! is_string($mode) || ! in_array($mode, self::BRIDGE_PROMPT_MODES, true)) {
            throw new InvalidArgumentException(
                'bridge_prompt.mode must be one of '.implode(', ', self::BRIDGE_PROMPT_MODES).'.'
            );
        }

        $text = $spec['text'] ?? null;
        if ($text !== null && ! is_string($text)) {
            throw new InvalidArgumentException('bridge_prompt.text must be a string.');
        }
        $hasText = $text !== null && $text !== '';

        if (in_array($mode, ['default', 'off'], true) && $hasText) {
            throw new InvalidArgumentException("bridge_prompt.text is not allowed with mode \"{$mode}\"; it would be discarded.");
        }
        if (in_array($mode, ['append', 'replace'], true) && ! $hasText) {
            throw new InvalidArgumentException("bridge_prompt.text is required with mode \"{$mode}\".");
        }
        if ($hasText && strlen($text) > self::MAX_BRIDGE_PROMPT_BYTES) {
            throw new InvalidArgumentException('bridge_prompt.text exceeds '.self::MAX_BRIDGE_PROMPT_BYTES.' bytes.');
        }

        if ($mode === 'default') {
            return null;
        }

        return $hasText ? ['mode' => $mode, 'text' => $text] : ['mode' => $mode];
    }

    /**
     * Build a payload from the internal relay's HTTP body.
     *
     * A named mapping rather than an inline one in BridgeWebSocketServer,
     * because this is the half a test cannot otherwise reach: the relay path
     * copies the payload into an HTTP body at one end and maps it back at the
     * other, and a test that re-implements that mapping proves only that the
     * test agrees with itself. Both the server and the parity test call this.
     *
     * Every value is coerced or defaulted here. The body is caller-supplied
     * JSON arriving inside a ReactPHP callback that has no try/catch of its
     * own, so a TypeError raised while shaping it does not fail one request —
     * it exits the process and drops every connected bridge.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function fromRelayBody(array $body, string $requestId): array
    {
        return self::build([
            'request_id' => $requestId,
            'conversation_id' => self::asString($body['conversation_id'] ?? ''),
            'provider' => self::asString($body['provider'] ?? ''),
            'message' => self::asString($body['message'] ?? ''),
            'system_prompt' => isset($body['system_prompt']) ? self::asString($body['system_prompt']) : null,
            'options' => $body['options'] ?? [],
            'cli_session_id' => isset($body['cli_session_id']) ? self::asString($body['cli_session_id']) : null,
            'history' => $body['history'] ?? null,
            'tools' => $body['tools'] ?? null,
            'working_dir' => isset($body['working_dir']) ? self::asString($body['working_dir']) : null,
            'attachments' => $body['attachments'] ?? null,
            'bridge_prompt' => $body['bridge_prompt'] ?? null,
        ]);
    }

    /**
     * Coerce a relay-body value to a string, refusing shapes that cannot be one.
     *
     * `(string) []` is a warning-then-"Array" under one PHP configuration and a
     * thrown Error under another, and `strict_types=1` makes an int where a
     * string is declared a TypeError. Neither belongs anywhere near the
     * WebSocket server's event loop.
     */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null || $value === false) {
            return '';
        }

        throw new InvalidArgumentException(
            'Expected a string in the relay body, got '.get_debug_type($value).'.'
        );
    }

    /**
     * Reduce each attachment to exactly the six fields the protocol defines.
     *
     * A malformed attachment throws rather than being skipped. Dropping one
     * quietly produces a turn where the assistant is simply never told about a
     * file the user watched themselves attach, and nothing anywhere says why.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     * @return array<int, array<string, mixed>>
     */
    private static function normaliseAttachments(array $attachments): array
    {
        $out = [];

        foreach ($attachments as $index => $attachment) {
            if (! is_array($attachment)) {
                throw new InvalidArgumentException("Attachment #{$index} must be an array.");
            }

            foreach (['id', 'name', 'mime_type', 'size', 'sha256', 'url'] as $key) {
                if (! isset($attachment[$key])) {
                    throw new InvalidArgumentException(
                        "Attachment #{$index} is missing \"{$key}\". Attachments need id, name, "
                        .'mime_type, size, sha256 and url — the bridge verifies size and sha256 '
                        .'after downloading, and refuses a url that is not on this server.'
                    );
                }
            }

            $out[] = [
                'id' => (string) $attachment['id'],
                'name' => (string) $attachment['name'],
                'mime_type' => (string) $attachment['mime_type'],
                'size' => (int) $attachment['size'],
                'sha256' => strtolower((string) $attachment['sha256']),
                'url' => (string) $attachment['url'],
            ];
        }

        return $out;
    }
}
