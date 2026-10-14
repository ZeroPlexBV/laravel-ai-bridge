<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Contracts\HasView;
use Tetrix\AiBridge\Contracts\ToolHandler;
use Tetrix\AiBridge\Facades\AiBridge;
use Tetrix\AiBridge\Streaming\BridgeStream;
use Tetrix\AiBridge\Tools\AbstractTool;
use Tetrix\AiBridge\Tools\ToolDefinition;
use Tetrix\AiBridge\Tools\ToolParameter;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\Tools\ViewNotFoundException;
use Tetrix\AiBridge\Tools\ViewRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/*
|--------------------------------------------------------------------------
| Tools with a view (MCP Apps): declaring one, and the views registry
|--------------------------------------------------------------------------
*/

final class ViewedGrantTool extends AbstractTool
{
    public function name(): string { return 'show_grant_request'; }

    public function description(): string { return 'Show the person a grant request to confirm'; }

    public function view(): ?string { return 'ui://studio/grant-request'; }

    protected function defineParameters(): array
    {
        return [new ToolParameter('grant_id', 'integer', 'The grant request')];
    }

    public function handle(array $params): mixed { return 'shown'; }
}

final class PlainAbstractTool extends AbstractTool
{
    public function name(): string { return 'plain'; }

    public function description(): string { return 'No view'; }

    protected function defineParameters(): array { return []; }

    public function handle(array $params): mixed { return 'ok'; }
}

final class ViewedHandler implements HasView, ToolHandler
{
    public function name(): string { return 'viewed_handler'; }

    public function description(): string { return 'A handler with a view'; }

    public function parameters(): array { return []; }

    public function handle(array $params): mixed { return 'ok'; }

    public function view(): ?string { return 'ui://app/handler'; }
}

function viewToolRegistry(): ToolRegistry
{
    $registry = new ToolRegistry();
    $registry->registerHandler(new ViewedGrantTool());
    $registry->registerHandler(new PlainAbstractTool());
    $registry->register('closure_view', 'A closure tool with a view', [], fn () => 'ok', view: 'ui://app/closure');
    $registry->register('closure_plain', 'A closure tool without one', [], fn () => 'ok');

    return $registry;
}

/** @param  list<array<string, mixed>>  $tools */
function toolsByName(array $tools): array
{
    return array_column($tools, null, 'name');
}

describe('declaring a view', function () {
    it('emits _meta.ui.resourceUri only for a tool with a view', function () {
        $tools = toolsByName(viewToolRegistry()->toArray());

        expect($tools['show_grant_request']['_meta'])->toBe(['ui' => ['resourceUri' => 'ui://studio/grant-request']])
            ->and($tools['closure_view']['_meta'])->toBe(['ui' => ['resourceUri' => 'ui://app/closure']])
            ->and($tools['plain'])->not->toHaveKey('_meta')
            ->and($tools['closure_plain'])->not->toHaveKey('_meta');
    });

    it('takes a ToolHandler view from HasView, and an AbstractTool has none by default', function () {
        expect(ToolDefinition::fromHandler(new ViewedHandler())->view)->toBe('ui://app/handler')
            ->and(ToolDefinition::fromHandler(new PlainAbstractTool())->view)->toBeNull();
    });

    it('refuses a view that is not a ui:// URI', function () {
        new ToolDefinition('bad_view', 'Desc', [], fn () => null, view: 'https://example.com/card');
    })->throws(InvalidArgumentException::class);

    it('keeps _meta out of the Chat Completions schema', function () {
        $schemas = viewToolRegistry()->toFunctionSchemas(['show_grant_request']);

        expect($schemas[0]['function'])->not->toHaveKey('_meta');
    });

    it('registers a closure tool with a view through AiBridge::registerTool()', function () {
        AiBridge::registerTool('facade_view', 'Facade tool', [], fn () => 'ok', view: 'ui://app/facade');

        expect(AiBridge::tools()->get('facade_view')->view)->toBe('ui://app/facade');
    });

    it('reaches the bridge in welcome.tools', function () {
        Event::fake();
        $manager = new BridgeConnectionManager();
        $manager->addConnection('user-1', 'conn-1');
        $handler = new MessageHandler($manager, app(TokenManager::class), viewToolRegistry());

        $welcome = $handler->handleMessage('conn-1', null, json_encode(['type' => 'hello', 'version' => '0.1', 'providers' => []]));
        $tools = toolsByName($welcome['tools']);

        expect($tools['show_grant_request']['_meta']['ui']['resourceUri'])->toBe('ui://studio/grant-request')
            ->and($tools['plain'])->not->toHaveKey('_meta');
    });

    it('reaches the bridge in a request tool list, allowlist applied', function () {
        $stream = new BridgeStream(new BridgeConnectionManager(), viewToolRegistry(), app(TokenManager::class), 'user-1');
        $stream->setConversationId('conv-1');
        $stream->setProvider('claude');
        $stream->setMessage('hi');
        $stream->setOptions(['allowed_tools' => ['show_grant_request', 'plain']]);

        $tools = toolsByName($stream->buildRequestBody()['tools']);

        expect(array_keys($tools))->toBe(['show_grant_request', 'plain'])
            ->and($tools['show_grant_request']['_meta']['ui']['resourceUri'])->toBe('ui://studio/grant-request')
            ->and($tools['plain'])->not->toHaveKey('_meta');
    });
});

describe('the views registry', function () {
    it('is the singleton behind AiBridge::views()', function () {
        expect(AiBridge::views())->toBeInstanceOf(ViewRegistry::class)
            ->and(AiBridge::views())->toBe(app(ViewRegistry::class));
    });

    it('renders a registered view per request, with its CSP', function () {
        $views = new ViewRegistry();
        $calls = 0;
        $views->register('ui://studio/grant-request', function () use (&$calls) {
            $calls++;

            return "<p>card {$calls}</p>";
        }, csp: ['connectDomains' => []]);

        expect($views->has('ui://studio/grant-request'))->toBeTrue()
            ->and($views->uris())->toBe(['ui://studio/grant-request'])
            ->and($views->render('ui://studio/grant-request'))->toBe(['html' => '<p>card 1</p>', 'csp' => ['connectDomains' => []]])
            ->and($views->render('ui://studio/grant-request')['html'])->toBe('<p>card 2</p>');
    });

    it('accepts only ui:// URIs', function (string $uri) {
        (new ViewRegistry())->register($uri, fn () => '');
    })->throws(InvalidArgumentException::class)->with(['https://x.test/card', 'card', 'ui://', '']);

    it('refuses the same URI twice', function () {
        $views = new ViewRegistry();
        $views->register('ui://app/card', fn () => 'a');
        $views->register('ui://app/card', fn () => 'b');
    })->throws(InvalidArgumentException::class, 'already registered');

    it('throws a typed exception for an unknown URI', function () {
        (new ViewRegistry())->render('ui://app/missing');
    })->throws(ViewNotFoundException::class);

    it('refuses a view that does not render to a string', function () {
        $views = new ViewRegistry();
        $views->register('ui://app/card', fn () => ['not' => 'html']);
        $views->render('ui://app/card');
    })->throws(UnexpectedValueException::class);
});
