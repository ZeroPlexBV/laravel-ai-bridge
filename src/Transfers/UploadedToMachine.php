<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Transfers;

/**
 * A file that is now on the machine, as the machine confirmed it.
 *
 * `fileId` is what the machine recorded it under: keep it, it is the only way to ask
 * for the file back (MachineFiles::open()/download()). Null only from a bridge that did
 * not record it, which a bridge with `file_uploads` always does.
 */
final class UploadedToMachine
{
    public function __construct(
        /** Absolute path on the machine: `<working_dir>/file-uploads/<name>`. */
        public readonly string $path,
        /** The name it was given there; differs from the offered one on a collision (`report-2.pdf`). */
        public readonly string $name,
        public readonly int $size,
        /** Lowercase hex SHA-256, computed here as the bytes passed and confirmed by the machine. */
        public readonly string $sha256,
        public readonly ?string $fileId,
    ) {}

    /** @return array{path: string, name: string, size: int, sha256: string, file_id: string|null} */
    public function toArray(): array
    {
        return ['path' => $this->path, 'name' => $this->name, 'size' => $this->size, 'sha256' => $this->sha256, 'file_id' => $this->fileId];
    }
}
