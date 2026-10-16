{{--
    <x-ai-bridge::chat /> — reference chat UI for the AI Bridge conversation API.

    A thin wrapper that renders the <ai-bridge-chat> Web Component and loads its
    pre-built bundle. The Web Component uses Shadow DOM, so it CANNOT conflict
    with the host application's CSS framework or JavaScript — no global Tailwind,
    no global Alpine, nothing leaks in or out.

    Fully optional: the package backend is usable without it. To customise the
    UI, build your own against the HTTP API, or fork the component — see the
    "Customizing the chat UI" section of the package README.

    Props:
      api               Base path of the AI Bridge API (default "/ai-bridge").
      thinking-visible  false to hide expandable thinking blocks (default true).

    The session's CSRF token goes along as csrf-token, so the component sends it
    (X-CSRF-TOKEN) on the routes the package checks it on (ai-bridge.csrf).

    Streaming is delivered over plain SSE (text/event-stream) tailed by the
    browser's native EventSource — no Reverb/Echo dependency.
--}}
@props([
    'api' => '/ai-bridge',
    'thinkingVisible' => true,
])

@php
    $aiBridgeCsrf = request()->hasSession() ? csrf_token() : '';
@endphp

<ai-bridge-chat
    api="{{ $api }}"
    assets="{{ $api }}/assets"
    thinking-visible="{{ $thinkingVisible ? 'true' : 'false' }}"
    csrf-token="{{ $aiBridgeCsrf }}"
></ai-bridge-chat>

<script src="{{ $api }}/assets/ai-bridge-chat.js" defer></script>
