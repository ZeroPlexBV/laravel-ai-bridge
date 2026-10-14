<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Contracts;

/**
 * A tool that names an interactive view (an MCP App) for a host to draw next to its call.
 *
 * Implemented by {@see \Tetrix\AiBridge\Tools\AbstractTool} (returning null); a plain
 * {@see ToolHandler} implements it to get the same. The URI must be registered in
 * {@see \Tetrix\AiBridge\Tools\ViewRegistry} (`AiBridge::views()`), which serves its HTML.
 */
interface HasView
{
    /**
     * The `ui://` URI of the tool's view, or null for a tool without one.
     */
    public function view(): ?string;
}
