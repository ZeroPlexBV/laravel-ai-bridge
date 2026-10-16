<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Tools;

use Tetrix\AiBridge\Contracts\HasView;
use Tetrix\AiBridge\Contracts\ToolHandler;

/**
 * Recommended base class for defining tools the AI can call.
 *
 * Subclassing AbstractTool is the preferred, structured authoring path: instead
 * of hand-writing a raw JSON Schema array, you declare each parameter as a
 * {@see ToolParameter} via {@see AbstractTool::defineParameters()}. Because
 * ToolParameter enforces a non-empty description for every parameter, it is
 * impossible to register a tool with undescribed parameters.
 *
 * Example:
 *
 *   final class RollDiceTool extends AbstractTool
 *   {
 *       public function name(): string { return 'roll_dice'; }
 *
 *       public function description(): string { return 'Roll one or more dice'; }
 *
 *       protected function defineParameters(): array
 *       {
 *           return [
 *               new ToolParameter('sides', 'integer', 'Number of sides on each die'),
 *               new ToolParameter('count', 'integer', 'Number of dice to roll', required: false),
 *           ];
 *       }
 *
 *       public function handle(array $params): mixed
 *       {
 *           $count = $params['count'] ?? 1;
 *           $rolls = [];
 *           for ($i = 0; $i < $count; $i++) {
 *               $rolls[] = random_int(1, (int) $params['sides']);
 *           }
 *
 *           return ['rolls' => $rolls, 'total' => array_sum($rolls)];
 *       }
 *   }
 */
abstract class AbstractTool implements HasView, ToolHandler
{
    /**
     * Get the tool's unique name.
     */
    abstract public function name(): string;

    /**
     * Get a human-readable description of the tool.
     */
    abstract public function description(): string;

    /**
     * Define the tool's parameters as a list of ToolParameter instances.
     *
     * Each ToolParameter enforces a non-empty description, guaranteeing the AI
     * receives a fully explained schema.
     *
     * @return array<int, ToolParameter>
     */
    abstract protected function defineParameters(): array;

    /**
     * Execute the tool with the given parameters.
     *
     * @param  array<string, mixed>  $params  The parameters passed by the AI.
     * @return mixed  The result to send back to the AI. Must be JSON-serializable.
     */
    abstract public function handle(array $params): mixed;

    /**
     * The `ui://` URI of this tool's view (an MCP App), or null for none (the default).
     *
     * Override to give the tool a view; register the view's HTML in `AiBridge::views()`
     * and return a {@see ToolResult::withView()} from handle().
     */
    public function view(): ?string
    {
        return null;
    }

    /**
     * Get the JSON Schema describing the tool's parameters.
     *
     * Built automatically from {@see AbstractTool::defineParameters()}; do not override.
     *
     * @return array<string, mixed>
     */
    final public function parameters(): array
    {
        return ToolParameter::toJsonSchema($this->defineParameters());
    }
}
