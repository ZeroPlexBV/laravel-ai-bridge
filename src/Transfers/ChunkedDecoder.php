<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Transfers;

/**
 * An incremental decoder for an HTTP/1.1 `Transfer-Encoding: chunked` body.
 *
 * The bridge POSTs a download as a stream (Node's fetch with a ReadableStream
 * body sends it chunked, with no Content-Length). A proxy with request
 * buffering on turns that into a Content-Length body; one with it off (which is
 * what streaming wants) passes the chunks through. The serve process reads the
 * body as it arrives, so it decodes as it arrives too: feed() whatever the
 * socket delivered and get back the payload bytes it contained.
 *
 * Chunk extensions are ignored and trailers are skipped, as RFC 9112 allows.
 */
final class ChunkedDecoder
{
    private string $buffer = '';

    /** Bytes still to read of the current chunk's data, or -1 when a size line is next. */
    private int $remaining = -1;

    /** True between a chunk's data and the CRLF that ends it. */
    private bool $expectCrlf = false;

    private bool $inTrailers = false;

    private bool $done = false;

    /** Largest chunk-size line accepted, to bound the buffer on a hostile stream. */
    private const MAX_LINE = 1024;

    public function isDone(): bool
    {
        return $this->done;
    }

    /**
     * Decode what arrived.
     *
     * @return string The payload bytes in $data (may be empty).
     *
     * @throws \UnexpectedValueException on a malformed stream.
     */
    public function feed(string $data): string
    {
        if ($this->done) {
            return '';
        }

        $this->buffer .= $data;
        $out = '';

        while ($this->buffer !== '') {
            if ($this->remaining > 0) {
                $take = substr($this->buffer, 0, $this->remaining);
                $out .= $take;
                $this->remaining -= strlen($take);
                $this->buffer = (string) substr($this->buffer, strlen($take));
                if ($this->remaining === 0) {
                    $this->expectCrlf = true;
                    $this->remaining = -1;
                }

                continue;
            }

            if ($this->expectCrlf) {
                if (strlen($this->buffer) < 2) {
                    break;
                }
                if (substr($this->buffer, 0, 2) !== "\r\n") {
                    throw new \UnexpectedValueException('chunk data not followed by CRLF');
                }
                $this->buffer = (string) substr($this->buffer, 2);
                $this->expectCrlf = false;

                continue;
            }

            $eol = strpos($this->buffer, "\r\n");
            if ($eol === false) {
                if (strlen($this->buffer) > self::MAX_LINE) {
                    throw new \UnexpectedValueException('chunk line too long');
                }
                break;
            }
            $line = substr($this->buffer, 0, $eol);
            $this->buffer = (string) substr($this->buffer, $eol + 2);

            if ($this->inTrailers) {
                if ($line === '') {
                    $this->done = true;
                    $this->buffer = '';

                    break;
                }

                continue; // a trailer field: ignored
            }

            $size = trim(explode(';', $line, 2)[0]);
            if ($size === '' || ! ctype_xdigit($size) || strlen($size) > 15) {
                throw new \UnexpectedValueException('bad chunk size');
            }
            $n = (int) hexdec($size);
            if ($n === 0) {
                $this->inTrailers = true;

                continue;
            }
            $this->remaining = $n;
        }

        return $out;
    }
}
