<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Tools;

use Closure;
use InvalidArgumentException;
use Tetrix\AiBridge\Contracts\HasView;
use Tetrix\AiBridge\Contracts\ToolHandler;

/**
 * Immutable value object representing a tool the AI can call.
 *
 * A ToolDefinition holds the tool's name, description, JSON Schema parameters,
 * the handler (closure or ToolHandler instance) that executes it, and the
 * `ui://` view (MCP App) a host may draw next to its call, if it has one.
 *
 * Construction validates the name, description, and parameters so that a tool
 * can never be registered with a malformed name, an empty description, or
 * parameter properties that lack a description.
 */
final class ToolDefinition
{
    /**
     * @param  string  $name  Unique tool name (e.g. 'roll_dice').
     * @param  string  $description  Human-readable description for the AI.
     * @param  array<string, mixed>  $parameters  JSON Schema for the tool's input parameters.
     * @param  Closure|ToolHandler  $handler  The callable that executes the tool.
     * @param  string|null  $view  The `ui://` URI of the tool's view, or null for none.
     *
     * @throws InvalidArgumentException If the name, description, parameters or view are invalid.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        private readonly Closure|ToolHandler $handler,
        public readonly ?string $view = null,
    ) {
        $this->validateName($name);
        $this->validateDescription($name, $description);
        $this->validateParameters($name, $parameters);
        if ($view !== null) {
            ViewRegistry::assertViewUri($view);
        }
    }

    /**
     * Ensure the tool name is non-empty and uses a safe identifier format.
     *
     * @throws InvalidArgumentException
     */
    private function validateName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Tool name must not be empty.');
        }

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $name)) {
            throw new InvalidArgumentException(sprintf(
                "Invalid tool name '%s'. Names must start with a letter and contain only "
                . 'letters, digits, underscores, or hyphens (max 64 characters).',
                $name,
            ));
        }
    }

    /**
     * Ensure the tool has a non-empty description.
     *
     * @throws InvalidArgumentException
     */
    private function validateDescription(string $name, string $description): void
    {
        if (trim($description) === '') {
            throw new InvalidArgumentException(sprintf(
                "Tool '%s' must have a non-empty description.",
                $name,
            ));
        }
    }

    /**
     * Ensure the parameters, when present, form a valid JSON Schema object in
     * which every declared property carries a non-empty description.
     *
     * An empty array is allowed and represents a tool that takes no parameters.
     * A schema without a "properties" key is also allowed (nothing to describe).
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidArgumentException
     */
    private function validateParameters(string $name, array $parameters): void
    {
        if ($parameters === []) {
            return;
        }

        if (($parameters['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException(sprintf(
                "Tool '%s' parameters must be a JSON Schema object with \"type\" => \"object\".",
                $name,
            ));
        }

        if (! array_key_exists('properties', $parameters)) {
            return;
        }

        if (! is_array($parameters['properties'])) {
            throw new InvalidArgumentException(sprintf(
                "Tool '%s' parameters \"properties\" must be an array.",
                $name,
            ));
        }

        foreach ($parameters['properties'] as $property => $schema) {
            if (! is_array($schema)) {
                throw new InvalidArgumentException(sprintf(
                    "Tool '%s' parameter '%s' must be a JSON Schema array.",
                    $name,
                    $property,
                ));
            }

            $description = $schema['description'] ?? null;

            if (! is_string($description) || trim($description) === '') {
                throw new InvalidArgumentException(sprintf(
                    "Tool '%s' parameter '%s' must have a non-empty description.",
                    $name,
                    $property,
                ));
            }
        }
    }

    /**
     * Create a ToolDefinition from a ToolHandler class instance.
     */
    public static function fromHandler(ToolHandler $handler): self
    {
        return new self(
            name: $handler->name(),
            description: $handler->description(),
            parameters: $handler->parameters(),
            handler: $handler,
            view: $handler instanceof HasView ? $handler->view() : null,
        );
    }

    /**
     * Execute the tool with the given parameters.
     *
     * @param  array<string, mixed>  $params
     * @return mixed
     */
    public function execute(array $params): mixed
    {
        if ($this->handler instanceof ToolHandler) {
            return $this->handler->handle($params);
        }

        return ($this->handler)($params);
    }

    /**
     * Serialize the tool definition to the format expected by Chat Completions APIs.
     *
     * @return array<string, mixed>
     */
    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->parameters,
            ],
        ];
    }

    /**
     * Serialize the tool definition to a plain array (useful for WebSocket transmission).
     *
     * A tool with a view carries `_meta.ui.resourceUri`, the MCP Apps field the bridge
     * (0.27+) copies into the CLI's tool list. Absent for a tool without one, so the
     * definition of every other tool is unchanged.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->parameters,
        ];

        if ($this->view !== null) {
            $array['_meta'] = ['ui' => ['resourceUri' => $this->view]];
        }

        return $array;
    }
}
