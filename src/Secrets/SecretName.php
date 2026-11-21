<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Secrets;

/**
 * What a secret's name may be: the file name in the machine's store, so a plain label that can
 * never climb out of the folder. The bridge checks the same rule before it touches a file.
 */
final class SecretName
{
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    public const MAX_DESCRIPTION = 1000;

    /** The longest value accepted, in bytes: a key file, not a database dump. */
    public const MAX_VALUE_BYTES = 65536;

    public static function valid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1 && ! str_contains($name, '..');
    }
}
