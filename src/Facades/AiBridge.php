<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Contracts\ToolHandler;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Streaming\TurnInputResult;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/**
 * Facade for the AiBridgeManager.
 *
 * @method static StreamHandler stream(string $conversationId, string $message, array $options = [])
 * @method static StreamedResponse streamToResponse(string $conversationId, string $message, array $options = [])
 * @method static StreamHandler streamConversation(Conversation $conversation, string $message, array $options = [])
 * @method static StreamedResponse streamConversationToResponse(Conversation $conversation, string $message, array $options = [])
 * @method static string startConversationStream(Conversation $conversation, string $message, array $options = [])
 * @method static AiBridgeManager registerTool(string $name, string $description, array $parameters, Closure $handler)
 * @method static AiBridgeManager registerToolHandler(ToolHandler $handler)
 * @method static ToolRegistry tools()
 * @method static BridgeConnectionManager connections()
 * @method static bool hasBridge(int|string $userId)
 * @method static TurnInputResult sendTurnInput(string $requestId, string $messageId, string|array $content, int|string|null $userId = null)
 * @method static bool inputOpen(string $requestId)
 * @method static ProviderMode mode()
 * @method static AiBridgeManager resolveConversationsUsing(Closure $resolver)
 * @method static AiBridgeManager resolveConnectionsUsing(Closure $resolver)
 * @method static AiBridgeManager resolveAttachmentsUsing(Closure $resolver)
 * @method static AiBridgeManager storeAttachmentUsing(Closure $store)
 *
 * @see AiBridgeManager
 */
class AiBridge extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return AiBridgeManager::class;
    }
}
