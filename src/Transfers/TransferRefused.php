<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Transfers;

/**
 * A file transfer that did not happen, or did not finish.
 *
 * `status` is the HTTP status to give the browser. `reason` is stable and meant to be
 * branched on; the message is a sentence a person can act on. Codes:
 *
 *  - `not_connected` (409): the machine is not connected.
 *  - `unsupported` (409): its bridge is too old (needs 0.18+ for files).
 *  - `upload_too_large` (413): over the machine's per-file cap (`max_file_bytes`).
 *  - `length_required` (411), `invalid_request` (400), `size_mismatch` (400).
 *  - the machine's own refusal codes: `upload_refused`, `upload_failed`,
 *    `upload_cancelled`, `working_dir_not_allowed`, `working_dir_not_found` (422);
 *    `file_unknown`, `file_gone` (410); `file_changed` (409); `file_refused`,
 *    `file_failed` (502).
 *  - `pickup_timeout`, `stalled`, `not_confirmed`, `no_answer`, `never_started`,
 *    `timeout` (504): something stopped moving.
 *  - `machine_gone`, `machine_stopped`, `upload_mismatch`, `unreachable` (502).
 *  - `client_gone` (499): the browser went away.
 */
final class TransferRefused extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        /** The stable reason code (see the class docblock). */
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** @param  array<string, mixed>|null  $json */
    public static function fromAnswer(int $status, ?array $json, string $fallback): self
    {
        $code = is_string($json['code'] ?? null) && $json['code'] !== '' ? $json['code'] : 'failed';
        $error = is_string($json['error'] ?? null) && $json['error'] !== '' ? $json['error'] : $fallback;

        return new self($status >= 400 ? $status : 502, $code, $error);
    }
}
