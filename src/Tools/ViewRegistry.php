<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Tools;

use Closure;
use InvalidArgumentException;
use Stringable;
use UnexpectedValueException;

/**
 * The `ui://` views (MCP Apps) this server serves, reachable as `AiBridge::views()`.
 *
 * A tool names its view by URI ({@see \Tetrix\AiBridge\Contracts\HasView}); the host
 * that draws the call asks this registry for the view's HTML. The HTML is produced
 * per request by the registered closure, so it can be a rendered Blade view.
 *
 * Registered as a singleton, at boot, like the {@see ToolRegistry}.
 */
class ViewRegistry
{
    /** @var array<string, array{html: Closure, csp: array<string, mixed>}> */
    private array $views = [];

    /**
     * Register a view.
     *
     * @param  string  $uri  The `ui://` URI tools name it by.
     * @param  Closure(): (string|Stringable)  $html  Produces the view's HTML.
     * @param  array<string, mixed>  $csp  The MCP Apps `_meta.ui.csp` (empty = nothing external).
     * @return $this
     *
     * @throws InvalidArgumentException For a URI that is not `ui://`, or one already registered.
     */
    public function register(string $uri, Closure $html, array $csp = []): static
    {
        self::assertViewUri($uri);

        // Replacing silently would hide two registrations of one URI, where one of
        // them is a mistake; the tool would show whichever booted last.
        if ($this->has($uri)) {
            throw new InvalidArgumentException("A view is already registered for '{$uri}'.");
        }

        $this->views[$uri] = ['html' => $html, 'csp' => $csp];

        return $this;
    }

    public function has(string $uri): bool
    {
        return isset($this->views[$uri]);
    }

    /**
     * Render a view: its HTML, produced now, and its CSP.
     *
     * @return array{html: string, csp: array<string, mixed>}
     *
     * @throws ViewNotFoundException When the URI is not registered.
     * @throws UnexpectedValueException When the closure returns something other than a string.
     */
    public function render(string $uri): array
    {
        $view = $this->views[$uri] ?? throw ViewNotFoundException::for($uri);

        $html = ($view['html'])();
        if ($html instanceof Stringable) {
            $html = (string) $html;
        }
        if (! is_string($html)) {
            throw new UnexpectedValueException("The view '{$uri}' must render to a string.");
        }

        return ['html' => $html, 'csp' => $view['csp']];
    }

    /**
     * Every registered URI.
     *
     * @return list<string>
     */
    public function uris(): array
    {
        return array_keys($this->views);
    }

    /**
     * Refuse anything that is not a `ui://` URI, the scheme MCP Apps resources use.
     *
     * @throws InvalidArgumentException
     */
    public static function assertViewUri(string $uri): void
    {
        if (! str_starts_with($uri, 'ui://') || trim(substr($uri, 5)) === '') {
            throw new InvalidArgumentException("A view URI must be a ui:// URI, got '{$uri}'.");
        }
    }
}
