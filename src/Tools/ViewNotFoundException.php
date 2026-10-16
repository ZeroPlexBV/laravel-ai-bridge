<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Tools;

use InvalidArgumentException;

/**
 * A `ui://` view was asked for that nobody registered in the {@see ViewRegistry}.
 */
final class ViewNotFoundException extends InvalidArgumentException
{
    public static function for(string $uri): self
    {
        return new self("No view is registered for '{$uri}'.");
    }
}
