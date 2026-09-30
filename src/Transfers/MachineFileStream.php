<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Transfers;

/**
 * A file on its way from a machine, after the machine said it has it.
 *
 * `status` is what to answer the browser (200 whole file, 206 a range, 416 a range past
 * the end); `length` is how many bytes will follow (end - start + 1, 0 for 416).
 * Read them with pipe() or read(); close() when done or abandoning it (the machine is
 * then told to stop).
 */
final class MachineFileStream
{
    private int $passed = 0;

    /** @param  resource  $socket */
    public function __construct(
        private mixed $socket,
        public readonly int $status,
        public readonly int $size,
        public readonly int $start,
        public readonly int $end,
        public readonly int $length,
    ) {}

    /**
     * Up to $max bytes, or '' at the end.
     *
     * @throws TransferRefused When the transfer broke off before `length` bytes.
     */
    public function read(int $max = 65536): string
    {
        if ($this->passed >= $this->length || ! is_resource($this->socket)) {
            return '';
        }

        $piece = fread($this->socket, min($max, $this->length - $this->passed));
        if ($piece === false || $piece === '') {
            $timedOut = stream_get_meta_data($this->socket)['timed_out'] ?? false;
            if ($timedOut || feof($this->socket)) {
                $this->close();

                throw new TransferRefused(502, $timedOut ? 'stalled' : 'machine_stopped',
                    "The machine stopped sending the file after {$this->passed} of {$this->length} bytes.");
            }

            return $this->read($max);
        }

        $this->passed += strlen($piece);

        return $piece;
    }

    /**
     * Hand every byte to $write until the end. $write returns false to stop early (the
     * browser went away), which closes the transfer.
     *
     * @param  callable(string): bool  $write
     * @return int Bytes passed.
     */
    public function pipe(callable $write): int
    {
        try {
            while (($piece = $this->read()) !== '') {
                if ($write($piece) === false) {
                    break;
                }
            }
        } catch (TransferRefused) {
            // Headers are out; all that is left is to stop. The short body tells the browser.
        } finally {
            $this->close();
        }

        return $this->passed;
    }

    public function complete(): bool
    {
        return $this->passed >= $this->length;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
