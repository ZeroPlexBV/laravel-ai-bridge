{{--
    The page an upstream MCP server's sign-in ends on (UpstreamOAuthCallbackController).
    Override it with `php artisan vendor:publish --tag=ai-bridge-views`, or point
    ai-bridge.upstream_oauth.view at your own. Variables: $ok (bool), $server (?string),
    $code (?string: unknown_state, expired, forbidden, denied, timeout, ...), $message (?string).
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $ok ? 'Connected' : 'Sign-in not completed' }}</title>
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f6f7f9; color: #1d2433; }
        main { max-width: 28rem; padding: 2rem; background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
        p { margin: .25rem 0; }
        code { font-size: .85em; color: #5b6475; }
    </style>
</head>
<body>
<main>
    @if ($ok)
        <h1>Connected{{ $server ? ' to '.$server : '' }}</h1>
        <p>You can close this tab.</p>
    @else
        <h1>Sign-in not completed{{ $server ? ' for '.$server : '' }}</h1>
        <p>{{ $message }}</p>
        @if ($code)
            <p><code>{{ $code }}</code></p>
        @endif
    @endif
</main>
</body>
</html>
