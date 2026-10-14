<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Tools;

use JsonSerializable;

/**
 * A tool result written for two readers: the model, which gets the text, and the
 * tool's view (an MCP App), which also gets the structured content.
 *
 * Return one from the handler of a tool with a view. The CLI only ever receives
 * the text (`tool_resolve`); the server keeps the full result and hands it to the
 * host on the stream's `tool_result` as `ui.result` (see PROTOCOL.md).
 *
 * Returned from a tool WITHOUT a view it is simply its text.
 */
final class ToolResult implements JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $structured
     */
    private function __construct(
        public readonly string $text,
        public readonly ?array $structured = null,
    ) {}

    /**
     * @param  string  $text  What the model reads.
     * @param  array<string, mixed>  $structured  What the view reads besides (`structuredContent`).
     */
    public static function withView(string $text, array $structured): self
    {
        return new self($text, $structured);
    }

    /**
     * The MCP `CallToolResult` a view receives.
     *
     * @return array{content: list<array{type: string, text: string}>, structuredContent?: array<string, mixed>}
     */
    public function toCallToolResult(): array
    {
        $result = ['content' => [['type' => 'text', 'text' => $this->text]]];

        if ($this->structured !== null) {
            $result['structuredContent'] = $this->structured;
        }

        return $result;
    }

    /** Encoded as the text alone: that is what the model is given. */
    public function jsonSerialize(): string
    {
        return $this->text;
    }
}
