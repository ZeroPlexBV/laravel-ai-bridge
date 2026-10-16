/**
 * <ai-bridge-chat> — reference chat UI for the AI Bridge conversation API.
 *
 * A self-contained Web Component. All markup + styles live inside a Shadow
 * DOM, so it CANNOT conflict with the host application's CSS framework or
 * JavaScript (no global Tailwind, no global Alpine — nothing leaks in or out).
 *
 * It is a thin client of the package's public HTTP API; all real logic is
 * server-side. To customise the UI, either build your own UI against that
 * same HTTP API (recommended) or fork this file. See the package README.
 *
 * Attributes:
 *   api               Base path of the AI Bridge API (default "/ai-bridge").
 *   thinking-visible  "false" to hide expandable thinking blocks (default true).
 *   assets            Base path the package serves the component bundle from.
 *
 * Streaming model:
 *   POST /conversations/{id}/stream returns JSON {request_id}. The browser
 *   immediately opens an EventSource on /streams/{rid}/events; native
 *   EventSource reconnection (Last-Event-ID) is what makes a refresh,
 *   tab-switch or network blip recover the in-flight reply for free.
 */
(function () {
    'use strict';

    const CSS = `
    :host { all: initial; }
    * { box-sizing: border-box; }
    .root {
        display: flex; height: 80vh; min-height: 520px; overflow: hidden;
        border: 1px solid #e5e7eb; border-radius: 12px; background: #fff;
        font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
        color: #111827; font-size: 14px;
    }
    .sidebar { width: 280px; display: flex; flex-direction: column;
        border-right: 1px solid #e5e7eb; background: #f9fafb; }
    .sidebar-head { display: flex; align-items: center; justify-content: space-between; padding: 12px; }
    .sidebar-head b { font-size: 13px; }
    .conv-list { flex: 1; overflow-y: auto; padding: 0 8px 8px; }
    .conv { display: flex; justify-content: space-between; align-items: center;
        padding: 8px 10px; border-radius: 8px; cursor: pointer; margin-bottom: 4px; }
    .conv:hover { background: #f3f4f6; }
    .conv.active { background: #e5e7eb; }
    .conv .title { font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .conv .sub { font-size: 11px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .conv .del { color: #9ca3af; visibility: hidden; background: none; border: 0; cursor: pointer; }
    .conv:hover .del { visibility: visible; }
    .empty { padding: 16px 8px; font-size: 12px; color: #9ca3af; }
    .setup { border-top: 1px solid #e5e7eb; padding: 12px; }
    .setup > button { width: 100%; display: flex; justify-content: space-between;
        background: none; border: 0; cursor: pointer; font-size: 12px; color: #4b5563; font-weight: 500; }
    .conn { border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; padding: 8px;
        font-size: 11px; margin-top: 8px; cursor: pointer; }
    .conn:hover { border-color: #9ca3af; background: #f9fafb; }
    .conn b { display: block; }
    .conn span { color: #6b7280; }
    .dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%;
        margin-right: 5px; vertical-align: middle; }
    .dot.on { background: #10b981; }
    .dot.off { background: #d1d5db; }
    button.ghost.danger { color: #b91c1c; }
    .main { flex: 1; display: flex; flex-direction: column; }
    .placeholder { flex: 1; display: flex; align-items: center; justify-content: center; color: #9ca3af; }
    .header { display: flex; align-items: center; justify-content: space-between;
        border-bottom: 1px solid #e5e7eb; padding: 10px 16px; }
    .header .name { font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 2px;
        min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .badge { background: #f3f4f6; color: #4b5563; border-radius: 999px; padding: 2px 8px; font-size: 11px; margin-left: 6px; }
    .stale { background: #fffbeb; color: #92400e; border-bottom: 1px solid #fde68a; padding: 6px 16px; font-size: 11px; }
    .messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 14px; }
    .msg { display: flex; }
    .msg.user { justify-content: flex-end; }
    .msg .stack { max-width: 80%; display: flex; flex-direction: column; gap: 8px; }
    .bubble { padding: 8px 14px; border-radius: 16px; white-space: pre-wrap; word-break: break-word; }
    .msg.user .bubble { background: #111827; color: #fff; }
    .msg.assistant .bubble { background: #f3f4f6; color: #111827; }
    .think { border: 1px solid #ddd6fe; background: #f5f3ff; border-radius: 12px; padding: 6px 12px; font-size: 12px; color: #6d28d9; }
    .think button { background: none; border: 0; cursor: pointer; color: #6d28d9; font-weight: 500; padding: 0; }
    .think .body { margin-top: 4px; color: #7c3aed; white-space: pre-wrap; }
    .tool { border: 1px solid #bfdbfe; background: #eff6ff; border-radius: 12px; padding: 8px 12px; font-size: 12px; }
    .tool b { color: #1d4ed8; }
    .tool pre { margin: 4px 0 0; overflow-x: auto; color: #2563eb; font-size: 11px; }
    .tool .res { margin-top: 4px; border-top: 1px solid #bfdbfe; padding-top: 4px; color: #2563eb; }
    .tool .res.err { color: #b91c1c; border-top-color: #fecaca; }
    .pulse { display: flex; align-items: center; gap: 4px; background: #f3f4f6; color: #6b7280;
        border-radius: 16px; padding: 8px 14px; width: fit-content; }
    .pulse i { width: 6px; height: 6px; border-radius: 50%; background: #9ca3af; display: inline-block;
        animation: ab-blink 1.2s infinite; }
    .pulse i:nth-child(3) { animation-delay: .2s; } .pulse i:nth-child(4) { animation-delay: .4s; }
    @keyframes ab-blink { 0%,100%{opacity:.3} 50%{opacity:1} }
    .err { background: #fef2f2; color: #b91c1c; border-radius: 8px; padding: 8px 12px; font-size: 12px; }
    .composer { border-top: 1px solid #e5e7eb; padding: 12px; display: flex; gap: 8px; }
    input, select, textarea { font: inherit; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 10px; color: #111827; }
    input:focus, select, textarea:focus { outline: none; }
    .composer input { flex: 1; }
    button.primary { background: #111827; color: #fff; border: 0; border-radius: 8px;
        padding: 8px 16px; cursor: pointer; font-weight: 500; }
    button.primary:disabled { opacity: .4; cursor: not-allowed; }
    button.small { background: #111827; color: #fff; border: 0; border-radius: 6px;
        padding: 4px 10px; font-size: 12px; cursor: pointer; }
    button.ghost { background: none; border: 0; color: #4b5563; cursor: pointer; }
    button.dashed { width: 100%; border: 1px dashed #d1d5db; background: none; border-radius: 8px;
        padding: 6px; font-size: 11px; color: #4b5563; cursor: pointer; margin-top: 8px; }
    .overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: flex;
        align-items: center; justify-content: center; padding: 16px; z-index: 50;
        font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
        color: #111827; font-size: 14px; }
    .modal { background: #fff; border-radius: 12px; padding: 20px; width: 100%; max-width: 420px; }
    .modal h3 { margin: 0 0 12px; font-size: 16px; }
    .modal label { display: block; font-size: 12px; font-weight: 500; color: #4b5563; margin-bottom: 4px; }
    .modal select, .modal input, .modal textarea { width: 100%; margin-bottom: 12px; }
    .modal .actions { display: flex; justify-content: flex-end; gap: 8px; }
    .hint { font-size: 12px; color: #4b5563; margin: 0 0 10px; }
    .cmd { background: #111827; color: #f3f4f6; border-radius: 8px; padding: 12px;
        font-size: 13px; line-height: 1.5; overflow-x: auto; white-space: pre-wrap;
        word-break: break-all; cursor: pointer; user-select: all;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .cmd:hover { background: #1f2937; }
    .cmd-hint { font-size: 11px; color: #6b7280; margin-top: 6px; }
    .cmd-hint.ok { color: #047857; font-weight: 500; }
    .hidden { display: none !important; }

    /* Hamburger toggle for the sidebar drawer — hidden on desktop, where the
       sidebar is always visible; revealed by the mobile media query below. */
    .burger { display: none; align-items: center; justify-content: center;
        background: none; border: 0; cursor: pointer; font-size: 18px; line-height: 1;
        color: #4b5563; padding: 4px 8px; margin-right: 2px; }
    .burger:hover { color: #111827; }
    /* Backdrop behind the open drawer — only ever visible on mobile. */
    .sidebar-backdrop { display: none; }

    /* ── Mobile / narrow screens ──────────────────────────────────────
       The 280px sidebar would eat most of a phone screen, so below 640px it
       becomes an off-canvas drawer: hidden by default, slid in over the chat
       by the .burger toggle (see togglesidebar), dismissed by the backdrop. */
    @media (max-width: 640px) {
        .root { position: relative; min-height: 0; height: 82vh; }
        .sidebar {
            position: absolute; top: 0; left: 0; bottom: 0;
            width: 84%; max-width: 320px; z-index: 40;
            transform: translateX(-100%); transition: transform .22s ease;
            box-shadow: 2px 0 18px rgba(0,0,0,.18);
        }
        .root.sidebar-open .sidebar { transform: translateX(0); }
        .sidebar-backdrop {
            display: block; position: absolute; inset: 0; z-index: 30;
            background: rgba(0,0,0,.4); opacity: 0; pointer-events: none;
            transition: opacity .22s ease;
        }
        .root.sidebar-open .sidebar-backdrop { opacity: 1; pointer-events: auto; }
        .burger { display: inline-flex; }
        .header { padding: 10px 12px; }
        .messages { padding: 12px; }
        .msg .stack { max-width: 92%; }
        .composer { padding: 8px; }
        .composer input { min-width: 0; }
    }
    `;

    class AiBridgeChat extends HTMLElement {
        connectedCallback() {
            if (this._booted) return;
            this._booted = true;
            // Handle for the coalesced stream render (see scheduleRender).
            this._renderPending = 0;

            this.api = this.getAttribute('api') || '/ai-bridge';
            this.assets = this.getAttribute('assets') || (this.api + '/assets');
            this.thinkingVisible = this.getAttribute('thinking-visible') !== 'false';
            // CSRF token for the state-changing routes (ai-bridge.csrf, decision 0009):
            // the csrf-token attribute <x-ai-bridge::chat /> sets, else the page's
            // <meta name="csrf-token">.
            this.csrfToken = this.getAttribute('csrf-token')
                || document.querySelector('meta[name="csrf-token"]')?.content || '';

            this.s = {
                conversations: [], connections: [], activeId: null, conv: null,
                messages: [], toolsStale: false, setupOpen: false,
                modal: null, bridgeCommand: null, cmdCopied: false,
                streaming: false, error: null, pulse: false, form: {},
                // Mobile only: whether the off-canvas sidebar drawer is open.
                sidebarOpen: false,
            };

            // Watchdog budgets (see armWatchdog). The first event after a send
            // must absorb a cold CLI start + a possible transparent
            // session_lost recovery, so it is given a wider window; once
            // events are flowing, a long silence is a fair stall signal.
            this.WATCHDOG_FIRST_MS = 120000;
            this.WATCHDOG_STEADY_MS = 45000;
            this._watchdogMisses = 0;

            this.root = this.attachShadow({ mode: 'open' });
            const style = document.createElement('style');
            style.textContent = CSS;
            this.root.appendChild(style);
            this.container = document.createElement('div');
            this.root.appendChild(this.container);

            this.root.addEventListener('click', (e) => this.onClick(e));
            this.renderAll();
            this.init();
        }

        async init() {
            await this.loadConnections();
            await this.loadConversations();
            this.renderAll();
            // Connection status is polled rather than pushed — a CLI bridge is
            // started manually by the user AFTER this UI loads, so its
            // providers/models are unknown on the first fetch. A 15s poll plus
            // a refresh on tab re-focus covers picking up the change. Both are
            // no-ops when nothing meaningful changed (see _connSnapshot).
            this.connSnapshot = this._connSnapshot(this.s.connections);
            this._onVisible = () => {
                if (document.visibilityState === 'visible') this.refreshConnections();
            };
            document.addEventListener('visibilitychange', this._onVisible);
            this._fallbackTimer = setInterval(() => this.refreshConnections(), 15000);
        }

        disconnectedCallback() {
            if (this._renderPending) {
                cancelAnimationFrame(this._renderPending);
                this._renderPending = 0;
            }
            if (this._onVisible) {
                document.removeEventListener('visibilitychange', this._onVisible);
                this._onVisible = null;
            }
            if (this._fallbackTimer) {
                clearInterval(this._fallbackTimer);
                this._fallbackTimer = null;
            }
            this.clearWatchdog();
            this.detachStreamSource();
        }

        // Snapshot of only the *meaningful* connection fields. The /connections
        // payload also carries last_connected_at, which the server refreshes on
        // every call — including it would make every snapshot differ and force
        // a needless re-render. Compare on identity + capabilities only.
        _connSnapshot(conns) {
            return JSON.stringify((conns || []).map((c) => ({
                id: c.id, type: c.type, name: c.name, providers: c.providers,
                connected: c.connected,
            })));
        }

        // Re-fetch connections, re-rendering only if something meaningful
        // changed. Triggered by a status push or by a tab re-focus.
        async refreshConnections() {
            let data;
            try {
                data = await this.apiCall('/connections');
            } catch (e) {
                return; // keep existing data on a transient failure
            }
            const conns = data.connections || [];
            const snapshot = this._connSnapshot(conns);
            if (snapshot !== this.connSnapshot) {
                this.connSnapshot = snapshot;
                this.s.connections = conns;
                this.renderAll();
            }
        }

        // ── HTTP ──────────────────────────────────────────────────────
        // Headers every request sends: JSON, plus the CSRF token the package checks on
        // state-changing routes for session-cookie requests. Without a token on the page,
        // Laravel's XSRF-TOKEN cookie (when readable) goes as X-XSRF-TOKEN instead.
        jsonHeaders(extra = {}) {
            const h = { 'Content-Type': 'application/json', ...extra };
            if (this.csrfToken) {
                h['X-CSRF-TOKEN'] = this.csrfToken;
            } else {
                const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
                if (m) h['X-XSRF-TOKEN'] = decodeURIComponent(m[1]);
            }
            return h;
        }
        async apiCall(path, opts = {}) {
            const res = await fetch(this.api + path, {
                credentials: 'same-origin',
                headers: this.jsonHeaders({ Accept: 'application/json' }),
                ...opts,
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.status === 204 ? null : res.json();
        }
        async loadConversations() {
            try {
                const d = await this.apiCall('/conversations');
                this.s.conversations = d.data || d || [];
            } catch (e) { this.s.conversations = []; }
        }
        async loadConnections() {
            try {
                const d = await this.apiCall('/connections');
                this.s.connections = d.connections || [];
            } catch (e) { this.s.connections = []; }
        }

        // ── rendering ─────────────────────────────────────────────────
        esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

        // opts.stick forces the transcript to the bottom regardless of where the
        // reader was — for renders where the new content IS the point (sending a
        // message, opening a conversation).
        renderAll(opts) {
            if (this._renderPending) {
                cancelAnimationFrame(this._renderPending);
                this._renderPending = 0;
            }
            const live = this._captureInputs();
            // Both of these have to be read BEFORE innerHTML is replaced.
            // Replacing it destroys and re-creates .messages — the scroller —
            // so afterwards scrollTop is 0 and scrollHeight has changed, and
            // every render would look like "the reader is at the bottom".
            const scroller = this.root.querySelector('.messages');
            const stick = (opts && opts.stick === true) || this._atBottom();
            const keepTop = scroller ? scroller.scrollTop : 0;
            const rootClass = 'root' + (this.s.sidebarOpen ? ' sidebar-open' : '');
            // .sidebar-backdrop sits under the drawer; it is display:none on
            // desktop and only catches taps (to close the drawer) on mobile.
            this.container.innerHTML =
                `<div class="${rootClass}">${this.sidebarHtml()}${this.mainHtml()}` +
                `<div class="sidebar-backdrop" data-act="togglesidebar"></div></div>` +
                `${this.modalHtml()}`;
            this._restoreInputs(live);
            this.scrollDown(stick, keepTop);
        }

        // Coalesce renders during a stream to at most one per animation frame.
        //
        // handleEvent used to render once per block; with partial streaming it
        // fires once per chunk — roughly 35 times for a long answer instead of
        // 3. Each render is a full innerHTML teardown of the whole transcript,
        // so without this the cost grows with conversation length on every
        // chunk, and the input refocus below runs often enough to disturb a
        // soft keyboard on mobile.
        scheduleRender() {
            if (this._renderPending) return;
            this._renderPending = requestAnimationFrame(() => {
                this._renderPending = 0;
                this.renderAll();
            });
        }

        // Is the transcript scrolled to (or very near) the bottom?
        //
        // 40px of slack so that "close enough" still counts as following along.
        // No .messages element yet means the first render, which should start
        // at the bottom.
        _atBottom() {
            const m = this.root.querySelector('.messages');
            if (!m) return true;
            return m.scrollHeight - m.scrollTop - m.clientHeight < 40;
        }

        // Capture in-progress text input before a re-render so an unrelated
        // re-render (notably the periodic connection poll) never wipes what the
        // user is typing, nor steals focus / caret position from them.
        _captureInputs() {
            const values = {};
            const active = this.root.activeElement;
            let focusKey = null, selStart = null, selEnd = null;
            this.root.querySelectorAll('input, textarea').forEach((el) => {
                const key = el.dataset.field || el.name;
                if (!key) return;
                values[key] = el.value;
                if (el === active) {
                    focusKey = key;
                    try { selStart = el.selectionStart; selEnd = el.selectionEnd; } catch (e) {}
                }
            });
            return { values, focusKey, selStart, selEnd };
        }

        // Restore values + focus + caret captured by _captureInputs(). Fields
        // that no longer exist (e.g. the modal closed) are simply skipped.
        _restoreInputs(live) {
            if (!live) return;
            this.root.querySelectorAll('input, textarea').forEach((el) => {
                const key = el.dataset.field || el.name;
                if (key && Object.prototype.hasOwnProperty.call(live.values, key)) {
                    el.value = live.values[key];
                }
            });
            if (live.focusKey) {
                const el = this.root.querySelector(
                    '[data-field="' + live.focusKey + '"], [name="' + live.focusKey + '"]');
                if (el) {
                    el.focus();
                    if (live.selStart != null) {
                        try { el.setSelectionRange(live.selStart, live.selEnd); } catch (e) {}
                    }
                }
            }
        }

        sidebarHtml() {
            const convs = this.s.conversations.map((c) => `
                <div class="conv ${c.id === this.s.activeId ? 'active' : ''}" data-act="open" data-id="${c.id}">
                    <div style="min-width:0">
                        <div class="title">${this.esc(c.title || 'Conversation #' + c.id)}</div>
                        <div class="sub">${this.esc((c.provider || c.mode) + (c.model ? ' · ' + c.model : ''))}</div>
                    </div>
                    <button class="del" data-act="del" data-id="${c.id}">✕</button>
                </div>`).join('');
            const conns = this.s.connections.map((cn) => {
                const provs = (cn.providers || []).map((p) => p.name).join(', ') || 'no providers';
                // Only CLI bridges have a process whose presence can change —
                // BYOK endpoints are always reachable, so they get no status dot.
                const dot = cn.type === 'bridge'
                    ? `<i class="dot ${cn.connected ? 'on' : 'off'}" title="${cn.connected ? 'Connected' : 'Not connected'}"></i>`
                    : '';
                return `<div class="conn" data-act="manageconn" data-id="${cn.id}" title="Manage this connection">` +
                    `<b>${dot}${this.esc(cn.name || cn.type)}</b><span>${this.esc(provs)}</span></div>`;
            }).join('');
            return `
            <div class="sidebar">
                <div class="sidebar-head"><b>Conversations</b>
                    <button class="small" data-act="newchat">+ New</button></div>
                <div class="conv-list">${convs || '<p class="empty">No conversations yet.</p>'}</div>
                <div class="setup">
                    <button data-act="togglesetup"><span>Setup &amp; connections</span><span>${this.s.setupOpen ? '▾' : '▸'}</span></button>
                    <div class="${this.s.setupOpen ? '' : 'hidden'}">
                        ${conns}
                        <button class="dashed" data-act="addbridge">+ Add a CLI bridge</button>
                        <button class="dashed" data-act="addbyok">+ Add a BYOK endpoint</button>
                    </div>
                </div>
            </div>`;
        }

        mainHtml() {
            // Hamburger that opens the sidebar drawer. Rendered in every state
            // (incl. the empty placeholder) so a mobile user can always reach
            // the conversation list; CSS hides it on desktop.
            const burger = `<button class="burger" data-act="togglesidebar" aria-label="Show conversations" title="Conversations">☰</button>`;
            if (!this.s.conv) {
                return `<div class="main">
                    <div class="header"><div class="name">${burger}Conversations</div><div></div></div>
                    <div class="placeholder">Select or start a conversation.</div>
                </div>`;
            }
            const c = this.s.conv;
            const msgs = this.s.messages.map((m, mi) => this.msgHtml(m, mi)).join('');
            return `
            <div class="main">
                <div class="header">
                    <div class="name">${burger}${this.esc(c.title || 'Conversation #' + c.id)}</div>
                    <div><span class="badge">${this.esc(c.provider || c.mode)}</span>${c.model ? `<span class="badge">${this.esc(c.model)}</span>` : ''}</div>
                </div>
                ${this.s.toolsStale ? '<div class="stale">This conversation runs on an older tool set — start a new conversation for the freshest tools.</div>' : ''}
                <div class="messages">
                    ${msgs}
                    ${this.s.pulse ? '<div class="pulse"><span>Thinking</span><i></i><i></i><i></i></div>' : ''}
                    ${this.s.error ? `<div class="err">${this.esc(this.s.error)}</div>` : ''}
                </div>
                <form class="composer" data-act="sendform">
                    <input name="draft" placeholder="Type a message…" ${this.s.streaming ? 'disabled' : ''} autocomplete="off">
                    <button class="primary" type="submit" ${this.s.streaming ? 'disabled' : ''}>Send</button>
                </form>
            </div>`;
        }

        msgHtml(m, mi) {
            const blocks = (m.blocks || []).map((b, bi) => {
                if (b.type === 'thinking') {
                    if (!this.thinkingVisible) return '';
                    return `<div class="think">
                        <button data-act="togglethink" data-mi="${mi}" data-bi="${bi}">${b._open ? '▾' : '▸'} Thinking</button>
                        <div class="body ${b._open ? '' : 'hidden'}">${this.esc(b.text)}</div></div>`;
                }
                if (b.type === 'tool_call') {
                    // Defensive: a tool_call block with no name carries nothing
                    // worth showing — never render an empty "🔧" block.
                    if (!b.tool_name) return '';
                    // The wire carries the provider's name verbatim; trimming
                    // the MCP namespace for display is this consumer's job.
                    const shown = String(b.tool_name).replace(/^mcp__[^_]+(?:_[^_]+)*?__/, '') || String(b.tool_name);
                    // "cut off" only when it really was. PHP also sets
                    // parameters_raw for valid-but-not-an-object JSON, which was
                    // not truncated at all — labelling that as cut off is a
                    // false statement about the data.
                    const argNote = b.parameters_truncated_bytes !== undefined
                        ? `   ← cut off, ${b.parameters_truncated_bytes} bytes in total`
                        : (b.parameters_raw !== undefined ? '   ← arguments could not be parsed' : '');
                    // Matches the standalone tool_result branch below, so a
                    // call reads the same live as it does after a reload.
                    const emptyResult = (b.result === undefined || b.result === null || b.result === '');
                    const resultBody = emptyResult
                        ? '(no output)'
                        : (typeof b.result === 'string' ? b.result : JSON.stringify(b.result));
                    // The KEY, not its value. A result of null means the tool
                    // returned nothing; no result key at all means it has not
                    // returned. The recorder and StreamEvent both go out of
                    // their way to keep that difference — and this drew them
                    // identically, so a finished call with no output looked
                    // exactly like one still running.
                    const showResult = 'result' in b;
                    return `<div class="tool"><b>🔧 ${this.esc(shown)}</b>
                        <pre>${this.esc((b.parameters_raw !== undefined
                            ? b.parameters_raw
                            : this.showArgs(b.parameters)) + argNote)}</pre>
                        ${showResult ? `<div class="res${b.is_error ? ' err' : ''}">${b.is_error ? '✕' : '→'} ${this.esc(resultBody)}</div>` : ''}</div>`;
                }
                if (b.type === 'tool_result') {
                    // Persisted conversations store tool results as their own
                    // blocks, so THIS is the branch a page reload takes — the
                    // live path merges them into the call instead. It used to
                    // ignore is_error entirely, so a failed command read as a
                    // success the moment the reader refreshed.
                    const r = b.result;
                    const empty = (r === undefined || r === null || r === '');
                    // A standalone block exists BECAUSE a result was reported,
                    // so it is always drawn. Returning nothing here deleted the
                    // record of a call that completed with no output — the same
                    // information the recorder took trouble to keep.
                    const body = empty ? '(no output)' : (typeof r === 'string' ? r : JSON.stringify(r));
                    return `<div class="tool"><div class="res${b.is_error ? ' err' : ''}">${b.is_error ? '✕' : '→'} ${this.esc(body)}</div></div>`;
                }
                // Skip empty text blocks — a blank bubble is just visual noise.
                if (!b.text) return '';
                return `<div class="bubble">${this.esc(b.text)}</div>`;
            }).join('');
            return `<div class="msg ${m.role}"><div class="stack">${blocks}</div></div>`;
        }

        modalHtml() {
            if (!this.s.modal) return '';
            let body = '';
            if (this.s.modal === 'chat') {
                const conn = this.selectedConnection();
                const provs = (conn ? conn.providers || [] : []);
                const models = ((provs.find((p) => p.name === this.s.form.provider) || {}).models) || [];
                body = `<h3>New conversation</h3>
                    <label>Connection</label>
                    <select data-field="connectionId"><option value="">— select —</option>
                        ${this.s.connections.map((c) => `<option value="${c.id}" ${String(c.id) === String(this.s.form.connectionId) ? 'selected' : ''}>${this.esc((c.name || c.type) + ' (' + c.type + ')')}</option>`).join('')}</select>
                    <label>Provider</label>
                    <select data-field="provider"><option value="">— select —</option>
                        ${provs.map((p) => `<option value="${this.esc(p.name)}" ${p.name === this.s.form.provider ? 'selected' : ''}>${this.esc(p.name)}</option>`).join('')}</select>
                    <label>Model</label>
                    <select data-field="model"><option value="">— default —</option>
                        ${models.map((m) => `<option value="${this.esc(m.id)}" ${m.id === this.s.form.model ? 'selected' : ''}>${this.esc(m.name || m.id)}</option>`).join('')}</select>
                    <label>System prompt (optional)</label>
                    <textarea data-field="systemPrompt" rows="3">${this.esc(this.s.form.systemPrompt || '')}</textarea>
                    <div class="actions"><button class="ghost" data-act="closemodal">Cancel</button>
                        <button class="primary" data-act="createchat" ${this.s.form.connectionId && this.s.form.provider ? '' : 'disabled'}>Create</button></div>`;
            } else if (this.s.modal === 'bridge') {
                body = this.s.bridgeCommand
                    ? `<h3>Add a CLI bridge</h3>
                       <p class="hint">Run this where your Codex / Claude / Gemini CLI is installed:</p>
                       <div class="cmd" data-act="copycmd" title="Click to copy">${this.esc(this.s.bridgeCommand)}</div>
                       <div class="cmd-hint ${this.s.cmdCopied ? 'ok' : ''}">${this.s.cmdCopied ? '✓ Copied to clipboard' : 'Click the command to copy it'}</div>
                       <div class="actions" style="margin-top:12px"><button class="primary" data-act="closemodal">Done</button></div>`
                    : `<h3>Add a CLI bridge</h3><label>Name (optional)</label><input data-field="name" value="${this.esc(this.s.form.name || '')}">
                       <div class="actions"><button class="ghost" data-act="closemodal">Cancel</button>
                       <button class="primary" data-act="createbridge">Create</button></div>`;
            } else if (this.s.modal === 'byok') {
                body = `<h3>Add a BYOK endpoint</h3>
                    <label>Name (optional)</label><input data-field="name" value="${this.esc(this.s.form.name || '')}">
                    <label>Endpoint</label><input data-field="endpoint" placeholder="https://api.openai.com" value="${this.esc(this.s.form.endpoint || '')}">
                    <label>API key</label><input data-field="apiKey" type="password" placeholder="sk-…" value="${this.esc(this.s.form.apiKey || '')}">
                    <div class="actions"><button class="ghost" data-act="closemodal">Cancel</button>
                        <button class="primary" data-act="createbyok" ${this.s.form.endpoint && this.s.form.apiKey ? '' : 'disabled'}>Save</button></div>`;
            } else if (this.s.modal === 'manage') {
                body = this.manageModalHtml();
            }
            return `<div class="overlay" data-act="overlay"><div class="modal">${body}</div></div>`;
        }

        // Body of the "manage connection" modal — view status, rename,
        // regenerate the token, or delete. Reached by clicking a connection
        // card. After a regenerate it shows the fresh command (reusing the
        // bridgeCommand state, exactly like the add-bridge flow).
        manageModalHtml() {
            const cn = this.s.connections.find((c) => String(c.id) === String(this.s.manageId));
            if (!cn) {
                return `<h3>Connection</h3>
                    <p class="hint">This connection no longer exists.</p>
                    <div class="actions"><button class="primary" data-act="closemodal">Close</button></div>`;
            }
            if (this.s.bridgeCommand) {
                return `<h3>New bridge command</h3>
                    <p class="hint">The previous token has been revoked. Run this where your
                       Codex / Claude / Gemini CLI is installed:</p>
                    <div class="cmd" data-act="copycmd" title="Click to copy">${this.esc(this.s.bridgeCommand)}</div>
                    <div class="cmd-hint ${this.s.cmdCopied ? 'ok' : ''}">${this.s.cmdCopied ? '✓ Copied to clipboard' : 'Click the command to copy it'}</div>
                    <div class="actions" style="margin-top:12px"><button class="primary" data-act="closemodal">Done</button></div>`;
            }
            const isBridge = cn.type === 'bridge';
            const provs = (cn.providers || []).map((p) => p.name).join(', ') || 'none';
            const status = isBridge
                ? `<p class="hint"><i class="dot ${cn.connected ? 'on' : 'off'}"></i>` +
                  `${cn.connected ? 'Connected' : 'Not connected'} · Providers: ${this.esc(provs)}</p>`
                : '';
            return `<h3>Manage ${isBridge ? 'CLI bridge' : 'BYOK endpoint'}</h3>
                ${status}
                <label>Name</label>
                <input data-field="name" value="${this.esc(this.s.form.name || '')}" placeholder="${this.esc(cn.type)}">
                <div class="actions" style="justify-content:space-between;margin-bottom:8px">
                    <button class="ghost danger" data-act="deleteconn">Delete</button>
                    ${isBridge ? '<button class="ghost" data-act="regenconn">Regenerate token</button>' : ''}
                </div>
                <div class="actions">
                    <button class="ghost" data-act="closemodal">Cancel</button>
                    <button class="primary" data-act="saveconn">Save</button>
                </div>`;
        }

        // ── events ────────────────────────────────────────────────────
        onClick(e) {
            const t = e.target.closest('[data-act]');
            if (!t) return;
            const act = t.dataset.act;
            const id = t.dataset.id;
            if (act === 'open') this.openConversation(Number(id));
            else if (act === 'togglesidebar') { this.s.sidebarOpen = !this.s.sidebarOpen; this.renderAll(); }
            else if (act === 'del') { e.stopPropagation(); this.deleteConversation(Number(id)); }
            else if (act === 'newchat') this.openModal('chat');
            else if (act === 'togglesetup') { this.s.setupOpen = !this.s.setupOpen; this.renderAll(); }
            else if (act === 'addbridge') { this.s.form = {}; this.s.bridgeCommand = null; this.s.cmdCopied = false; this.openModal('bridge'); }
            else if (act === 'addbyok') { this.s.form = {}; this.openModal('byok'); }
            else if (act === 'closemodal' || act === 'overlay') { if (act === 'overlay' && e.target !== t) return; this.s.modal = null; this.renderAll(); }
            else if (act === 'copycmd') this.copyCommand();
            else if (act === 'createchat') this.createConversation();
            else if (act === 'createbridge') this.createBridge();
            else if (act === 'createbyok') this.createByok();
            else if (act === 'manageconn') this.openManage(Number(id));
            else if (act === 'saveconn') this.saveConn();
            else if (act === 'regenconn') this.regenConn();
            else if (act === 'deleteconn') this.deleteConn();
            else if (act === 'togglethink') {
                const b = this.s.messages[t.dataset.mi].blocks[t.dataset.bi];
                b._open = !b._open; this.renderAll();
            }
        }

        bindFormInputs() {
            this.root.querySelectorAll('[data-field]').forEach((el) => {
                el.addEventListener('change', () => {
                    this.s.form[el.dataset.field] = el.value;
                    if (el.dataset.field === 'connectionId') { this.s.form.provider = ''; this.s.form.model = ''; this.renderAll(); }
                    else if (el.dataset.field === 'provider') { this.s.form.model = ''; this.renderAll(); }
                    else this.refreshModalButtons();
                });
            });
            // Enter inside a modal text field submits the modal's primary
            // action — modals are not <form>s, so the browser won't do it.
            this.root.querySelectorAll('.modal input').forEach((el) => {
                el.addEventListener('keydown', (e) => {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    this.submitModal();
                });
            });
            const form = this.root.querySelector('[data-act="sendform"]');
            if (form) form.addEventListener('submit', (e) => { e.preventDefault(); this.send(form.draft.value); });
        }
        // Update the modal's primary-button enabled state IN PLACE. A full
        // renderAll() here would rebuild the DOM in the window between a
        // button's mousedown and mouseup — destroying the button mid-click so
        // the click never lands, which is why "Create" used to need two
        // clicks. Toggling .disabled leaves the element (and the click) intact.
        refreshModalButtons() {
            const f = this.s.form;
            const toggle = (act, enabled) => {
                const b = this.root.querySelector('.modal button[data-act="' + act + '"]');
                if (b) b.disabled = !enabled;
            };
            if (this.s.modal === 'chat') toggle('createchat', !!(f.connectionId && f.provider));
            else if (this.s.modal === 'byok') toggle('createbyok', !!(f.endpoint && f.apiKey));
            // The 'bridge' and 'manage' modal primary buttons are never disabled.
        }

        // Commit any not-yet-blurred field values, then trigger the modal's
        // primary action. Backs Enter-to-submit, since a `keydown` does not
        // fire the `change` event that normally syncs fields into s.form.
        submitModal() {
            this.root.querySelectorAll('.modal [data-field]').forEach((el) => {
                this.s.form[el.dataset.field] = el.value;
            });
            this.renderAll();
            const btn = this.root.querySelector('.modal button.primary');
            if (btn && !btn.disabled) btn.click();
        }

        openModal(kind) { this.s.modal = kind; this.renderAll(); }
        selectedConnection() {
            return this.s.connections.find((c) => String(c.id) === String(this.s.form.connectionId)) || null;
        }

        // ── conversations ─────────────────────────────────────────────
        async openConversation(id) {
            this.s.activeId = id; this.s.error = null;
            this.s.sidebarOpen = false; // close the mobile drawer once a chat is picked
            // Drop any in-flight EventSource from a previous conversation. The
            // new one (if any) is attached below from streaming_request_id.
            this.detachStreamSource();
            this.s.streaming = false; this.s.pulse = false; this.current = null;
            let streamingRequestId = null;
            try {
                const d = await this.apiCall('/conversations/' + id);
                this.s.conv = d.conversation;
                this.s.toolsStale = !!d.tools_stale;
                streamingRequestId = d.streaming_request_id || null;
                this.s.messages = (d.messages || []).map((m) => ({
                    role: m.role,
                    blocks: (Array.isArray(m.blocks) && m.blocks.length)
                        ? m.blocks.map((b) => ({ ...b, _open: false }))
                        : [{ type: 'text', text: m.content || '' }],
                }));
            } catch (e) { this.s.error = e.message; }
            // Forced: _atBottom() would be measuring the PREVIOUS
            // conversation's scroller here, and a conversation is opened to
            // read its newest message, not its oldest.
            this.renderAll({ stick: true });

            // If a turn is currently in flight on this conversation, attach an
            // EventSource to its event buffer and replay from the beginning so
            // the in-flight assistant message rebuilds. The browser handles
            // any later reconnects on its own via Last-Event-ID.
            if (streamingRequestId) {
                this.assistant = { role: 'assistant', blocks: [] };
                this.s.messages.push(this.assistant);
                this.s.streaming = true;
                this.s.pulse = true;
                // Reattaching to a turn still in flight is a new turn as far as
                // this component is concerned; without clearing these it would
                // be inert, having terminated a previous one.
                this._terminated = false;
                this._frameNames = [];
                this.renderAll({ stick: true });
                this.attachStreamSource(streamingRequestId, { fromIndex: -1 });
                this.armWatchdog(this.WATCHDOG_FIRST_MS);
            }
        }
        async deleteConversation(id) {
            await this.apiCall('/conversations/' + id, { method: 'DELETE' });
            if (this.s.activeId === id) { this.s.activeId = null; this.s.conv = null; this.s.messages = []; }
            await this.loadConversations();
            this.renderAll();
        }
        async createConversation() {
            const conn = this.selectedConnection();
            try {
                const conv = await this.apiCall('/conversations', {
                    method: 'POST',
                    body: JSON.stringify({
                        mode: conn && conn.type === 'byok' ? 'byok' : 'bridge',
                        connection_id: this.s.form.connectionId,
                        provider: this.s.form.provider,
                        model: this.s.form.model || null,
                        system_prompt: this.s.form.systemPrompt || null,
                    }),
                });
                this.s.modal = null;
                await this.loadConversations();
                await this.openConversation(conv.id);
            } catch (e) { this.s.error = e.message; this.renderAll(); }
        }
        async createBridge() {
            try {
                const d = await this.apiCall('/connections', {
                    method: 'POST', body: JSON.stringify({ type: 'bridge', name: this.s.form.name || null }),
                });
                this.s.bridgeCommand = d.command;
                this.s.cmdCopied = false;
                await this.loadConnections();
            } catch (e) { this.s.error = e.message; this.s.modal = null; }
            this.renderAll();
        }

        // ── manage an existing connection (status / rename / regen / delete) ─
        openManage(id) {
            const cn = this.s.connections.find((c) => String(c.id) === String(id));
            this.s.manageId = id;
            this.s.bridgeCommand = null;
            this.s.cmdCopied = false;
            this.s.form = { name: (cn && cn.name) || '' };
            this.openModal('manage');
        }

        async saveConn() {
            try {
                await this.apiCall('/connections/' + this.s.manageId, {
                    method: 'PATCH', body: JSON.stringify({ name: this.s.form.name || null }),
                });
                this.s.modal = null;
                await this.loadConnections();
            } catch (e) { this.s.error = e.message; }
            this.renderAll();
        }

        async deleteConn() {
            const cn = this.s.connections.find((c) => String(c.id) === String(this.s.manageId));
            const label = (cn && (cn.name || cn.type)) || 'this connection';
            if (!confirm('Delete "' + label + '"? Any bridge currently connected with it '
                + 'will be disconnected. This cannot be undone.')) return;
            try {
                await this.apiCall('/connections/' + this.s.manageId, { method: 'DELETE' });
                this.s.modal = null;
                await this.loadConnections();
            } catch (e) { this.s.error = e.message; }
            this.renderAll();
        }

        async regenConn() {
            if (!confirm('Regenerate this bridge\'s token? The current token stops working '
                + 'immediately — any bridge using it is disconnected and must be restarted '
                + 'with the new command.')) return;
            try {
                const d = await this.apiCall('/connections/' + this.s.manageId + '/regenerate', { method: 'POST' });
                this.s.bridgeCommand = d.command;
                this.s.cmdCopied = false;
                await this.loadConnections();
            } catch (e) { this.s.error = e.message; this.s.modal = null; }
            this.renderAll();
        }

        // Copy the bridge command to the clipboard. Uses the async Clipboard
        // API where available, with an execCommand fallback for non-HTTPS
        // origins; brief "Copied" feedback is driven by the cmdCopied flag.
        async copyCommand() {
            const text = this.s.bridgeCommand || '';
            let ok = false;
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(text);
                    ok = true;
                }
            } catch (e) { ok = false; }
            if (!ok) {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                ta.remove();
            }
            this.s.cmdCopied = ok;
            this.renderAll();
            if (ok) {
                clearTimeout(this._cmdCopiedTimer);
                this._cmdCopiedTimer = setTimeout(() => {
                    this.s.cmdCopied = false;
                    if ((this.s.modal === 'bridge' || this.s.modal === 'manage') && this.s.bridgeCommand) this.renderAll();
                }, 2000);
            }
        }
        async createByok() {
            await this.apiCall('/connections', {
                method: 'POST',
                body: JSON.stringify({ type: 'byok', name: this.s.form.name || null, endpoint: this.s.form.endpoint, api_key: this.s.form.apiKey }),
            });
            this.s.modal = null;
            await this.loadConnections();
            this.renderAll();
        }

        // ── streaming ─────────────────────────────────────────────────
        async send(text) {
            text = (text || '').trim();
            if (this.s.streaming || !text || !this.s.activeId) return;
            this.s.error = null;
            // Clear the composer now that the message is accepted. Must happen
            // before renderAll(): _captureInputs() snapshots live field values
            // and _restoreInputs() would otherwise put the sent text straight
            // back into the input.
            const draftEl = this.root.querySelector('[name="draft"]');
            if (draftEl) draftEl.value = '';
            this.s.messages.push({ role: 'user', blocks: [{ type: 'text', text }] });
            this.assistant = { role: 'assistant', blocks: [] };
            this.s.messages.push(this.assistant);
            this.current = null;
            this.s.streaming = true;
            this.s.pulse = true;
            this._terminated = false;
            this._frameNames = [];
            // Forced: the message the reader just sent, and the "Thinking"
            // pulse under it, are the whole point of this render. Leaving it
            // conditional appends both off-screen for anyone who had scrolled up.
            this.renderAll({ stick: true });
            // Backstop: if nothing comes back at all the UI must not sit on
            // "Thinking" forever. armWatchdog() ends the turn; every received
            // event resets it (see handleEvent). The FIRST event gets a wider
            // budget because a cold CLI start or a transparent session_lost
            // recovery can delay it well past the steady-state timeout.
            this.armWatchdog(this.WATCHDOG_FIRST_MS);

            try {
                const res = await fetch(this.api + '/conversations/' + this.s.activeId + '/stream', {
                    method: 'POST', credentials: 'same-origin',
                    headers: this.jsonHeaders({ Accept: 'application/json' }),
                    body: JSON.stringify({ message: text }),
                });
                // 409: another tab is already streaming a response on this
                // conversation. The server returns the in-flight request_id;
                // we re-open the conversation (which attaches an EventSource
                // to that turn) so this tab sees the response alongside the
                // other one. The typed message goes back into the composer
                // so the player can re-send it once the current turn ends.
                if (res.status === 409) {
                    const body = await res.json().catch(() => ({}));
                    if (body && body.request_id) {
                        this.clearWatchdog();
                        await this.openConversation(this.s.activeId);
                        const draftBack = this.root.querySelector('[name="draft"]');
                        if (draftBack) draftBack.value = text;
                        this.s.error = 'Another window is already responding in this conversation. '
                            + 'Your message was not sent — re-send it once the current turn finishes.';
                        // Forced: this notice renders at the bottom of the
                        // transcript, so unforced it can be the one thing the
                        // reader never sees about a message that did not send.
                        this.renderAll({ stick: true });
                        return;
                    }
                }
                if (!res.ok) {
                    const ct = res.headers.get('content-type') || '';
                    const body = ct.includes('application/json') ? await res.json() : null;
                    throw new Error((body && (body.message || body.error)) || ('HTTP ' + res.status));
                }
                const d = await res.json();
                if (d.error) throw new Error(d.message || d.error);
                if (!d.request_id) throw new Error('Server did not return a request_id.');
                this.attachStreamSource(d.request_id, { fromIndex: -1 });
            } catch (e) { this.s.error = e.message; this.finish(); }
        }

        // Cancel the in-flight turn (server-side). The serve process notices
        // the abort flag, dispatches `cancelled`, sends `cancel` to the CLI.
        async stopTurn() {
            const rid = this._activeRequestId;
            if (!rid) return;
            try {
                await fetch(this.api + '/streams/' + encodeURIComponent(rid) + '/abort', {
                    method: 'POST', credentials: 'same-origin',
                    headers: this.jsonHeaders(),
                });
            } catch (e) { /* the EventSource will deliver the terminal regardless */ }
        }

        // A call's arguments, drawn the same whichever shape they arrive in.
        //
        // PHP's `json_decode($json, true)` cannot tell `{}` from `[]`, so a
        // no-argument call is held as `{}` live and comes back from the
        // transcript as `[]`. Rendered literally that is `{}` before a reload
        // and `[]` after one, for the same call — a difference with no meaning
        // that a reader is left to interpret.
        showArgs(params) {
            const empty = !params
                || (Array.isArray(params) ? params.length === 0 : Object.keys(params).length === 0);

            return empty ? '{}' : JSON.stringify(params, null, 2);
        }

        // ── watchdog: never let the UI hang on "Thinking" ─────────────
        // The buffer + EventSource model already makes refresh/reconnect
        // recoverable — the watchdog only catches the case where a turn
        // genuinely produces no events at all (the bridge is down, the
        // server crashed). It triggers a buffer-status check rather than an
        // immediate hard error, because the events may simply be lagging.
        armWatchdog(ms) {
            this.clearWatchdog();
            this._watchdog = setTimeout(async () => {
                if (!this.s.streaming) return;
                // ASK before declaring the turn dead. The comment above used to
                // promise a status check and the code made none: silence is not
                // evidence of a stall, because a tool running on the operator's
                // own machine — npm install, a test run, a large grep — emits
                // no events at all while it works. Any of those over 45 seconds
                // abandoned a perfectly healthy turn, showed a false "stalled"
                // error, left the tool call drawn as unfinished for ever, and
                // dropped every later event including the answer.
                const alive = await this.streamStillAlive();
                if (!this.s.streaming) return;
                if (alive) {
                    this._watchdogMisses = 0;
                    this.armWatchdog(ms);

                    return;
                }
                // A failed check is not proof either — but it cannot re-arm for
                // ever, or a server that has genuinely gone leaves the UI on
                // "Thinking" permanently, which is the thing this exists to
                // prevent. `null` means "could not tell"; three in a row is
                // enough to stop asking.
                if (alive === null && (this._watchdogMisses = (this._watchdogMisses || 0) + 1) < 3) {
                    this.armWatchdog(ms);

                    return;
                }
                this.s.error = 'No response received — the turn appears to be stalled. '
                    + 'Try refreshing the page; if the turn is still running, it will resume here.';
                this.finish();
            }, ms || this.WATCHDOG_STEADY_MS);
        }

        // true = the server still considers this turn live, false = it does not,
        // null = the question could not be answered.
        async streamStillAlive() {
            const rid = this._activeRequestId;
            if (!rid) return false;
            try {
                const r = await fetch(this.api + '/streams/' + encodeURIComponent(rid) + '/status',
                    { credentials: 'same-origin' });
                if (r.status === 404) return false;
                if (!r.ok) return null;
                const body = await r.json();

                return body.status === 'streaming';
            } catch (e) {
                return null;
            }
        }
        clearWatchdog() {
            if (this._watchdog) { clearTimeout(this._watchdog); this._watchdog = null; }
        }

        // ── per-turn EventSource ─────────────────────────────────────
        // Open an EventSource against the per-turn event buffer. The browser
        // does the reconnect work for us: a dropped connection comes back
        // with Last-Event-ID set automatically, and the server resumes from
        // the next index. fromIndex < 0 means "give me everything from the
        // start" (used on first attach and on conversation-open replay).
        attachStreamSource(requestId, opts) {
            this.detachStreamSource();
            this._activeRequestId = requestId;
            const fromIndex = (opts && typeof opts.fromIndex === 'number') ? opts.fromIndex : -1;
            const url = this.api + '/streams/' + encodeURIComponent(requestId)
                + '/events' + (fromIndex >= 0 ? ('?from_index=' + fromIndex) : '');
            const es = new EventSource(url, { withCredentials: true });
            this._eventSource = es;
            // EventSource fires a typed event per server `event:` line — wire
            // every name the server may emit. Keepalive lines have no event
            // name and a `:`-prefixed body, so they never reach a listener.
            const wire = (name) => es.addEventListener(name, (e) => this.onSseEvent(name, e));
            ['block_start', 'block_delta', 'block_stop', 'tool_call', 'tool_result',
             'rate_limit', 'done', 'error', 'cancelled'].forEach(wire);
            // onerror fires both on transient disconnects (the browser will
            // reconnect automatically) and on terminal failures. Only treat
            // it as terminal once readyState is CLOSED.
            es.onerror = () => {
                if (es.readyState === 2 /* CLOSED */) {
                    this.detachStreamSource();
                    // A CLOSED EventSource that we didn't ourselves close
                    // means the server stopped serving — leave the error in
                    // place so the user knows the turn didn't complete here,
                    // but the assistant row may still finalize server-side.
                    if (this.s.streaming) {
                        this.s.error = this.s.error
                            || 'The stream connection was closed unexpectedly. '
                               + 'Refresh to see the persisted result.';
                        this.finish();
                    }
                }
            };
        }

        detachStreamSource() {
            if (this._eventSource) {
                try { this._eventSource.close(); } catch (e) {}
                this._eventSource = null;
            }
            this._activeRequestId = null;
        }

        onSseEvent(name, ev) {
            let data = {};
            try { data = JSON.parse(ev.data || '{}'); } catch (e) { data = {}; }
            this.handleEvent({ event: name, data });
        }

        handleEvent(evt) {
            // Nothing may change the turn once it has ended. StreamHandler
            // guards every dispatch on `terminated` and this had no equivalent,
            // so a late frame was shown live and absent after a reload. Repo
            // A's frame guard made that reachable: an oversized frame emits a
            // terminal error, the server stops recording, and the bridge keeps
            // streaming the rest of the turn.
            if (this._terminated) return;
            // Any event means the turn is alive — push the watchdog back.
            if (this.s.streaming) this.armWatchdog();
            const d = evt.data || {};
            switch (evt.event) {
                case 'block_start':
                    // Show the "Thinking" pulse while a block is forming — it is
                    // cleared by the first delta (content arriving) or by the
                    // block_stop. Showing it on stop instead caused a flash on
                    // the final block: stop turned it on, done turned it off.
                    this.s.pulse = true;
                    // Finalise a block the stream never closed, so it keeps its
                    // arguments instead of rendering as "called with none" —
                    // the recorder does the same, and disagreeing about the
                    // same stream is worse than either answer alone.
                    this.closeToolBlock();
                    if ((d.block_type || 'text') === 'tool_call') {
                        // A tool the SERVER resolves arrives twice — as this
                        // block and as the dedicated 'tool_call' event, which
                        // carries the parsed arguments. Skip the block so the
                        // same call is not drawn twice.
                        //
                        // A tool that ran on the operator's own machine (Bash,
                        // Read, an editor) has no such event: this block is the
                        // only thing that will ever describe it. Skipping those
                        // too — which is what this used to do — is why a run of
                        // them showed as nothing at all.
                        if (typeof d.tool_name !== 'string' || !d.tool_name) {
                            this.current = null;
                            break;
                        }
                        this.current = {
                            type: 'tool_call', tool_name: d.tool_name,
                            tool_call_id: d.tool_call_id, text: '', parameters: {},
                            _fromStream: true,
                        };
                        // A frame can arrive before its own block — PROTOCOL.md
                        // does not pin the order down, which is why the recorder
                        // reconciles at persist rather than on arrival. Drop the
                        // frame's placeholder now that the richer block exists,
                        // or the same call is drawn twice live and once after a
                        // reload: the worst shape a bug can take.
                        const early = this.assistant.blocks.findIndex((b) =>
                            b.type === 'tool_call' && !b._fromStream && !b._claimed
                            && (d.tool_name === 'mcp__bridge__' + b.tool_name || d.tool_name === b.tool_name));
                        if (early !== -1) {
                            const placeholder = this.assistant.blocks[early];
                            this.current._frameParams = {
                                parameters: placeholder.parameters || {},
                                parameters_raw: placeholder.parameters_raw,
                                parameters_truncated_bytes: placeholder.parameters_truncated_bytes,
                            };
                            // Carry over anything already attached to the
                            // placeholder, so a result that arrived first is not
                            // discarded with it.
                            if ('result' in placeholder) {
                                this.current.result = placeholder.result;
                                this.current.is_error = placeholder.is_error;
                            }
                            this.assistant.blocks.splice(early, 1);
                            this.current._claimed = true;
                        }
                        this.assistant.blocks.push(this.current);
                        break;
                    }
                    this.current = { type: d.block_type || 'text', text: '', _open: false };
                    this.assistant.blocks.push(this.current);
                    break;
                case 'block_delta':
                    this.s.pulse = false;
                    if (this.current) this.current.text += (d.content || '');
                    break;
                case 'block_stop':
                    this.s.pulse = false;
                    // A locally-run tool's arguments arrive as the block's
                    // delta text, as one complete JSON object. Parse it here;
                    // keep the raw text if it does not parse, because a
                    // truncated call and a call with no arguments should not
                    // look the same.
                    this.closeToolBlock();
                    this.current = null;
                    break;
                case 'tool_call': {
                    // A server-resolved tool arrives twice: as a stream block
                    // and as this frame. The FRAME is dropped, because the
                    // block has everything it has and two things it does not —
                    // the CLI's tool_call_id, which is what tool_result events
                    // are keyed by, and its own arguments, so nothing is copied
                    // between calls and two parallel calls to the same tool
                    // cannot swap arguments when their frames return out of
                    // order.
                    //
                    // Matched on the bridge's own namespace only. An
                    // open-ended `__` suffix also matched the operator's own
                    // MCP servers, whose calls have no frame at all.
                    this._frameNames = this._frameNames || [];
                    this._frameNames.push(d.tool_name);
                    const claims = (b) => b && b.type === 'tool_call' && b._fromStream && !b._claimed
                        && (b.tool_name === 'mcp__bridge__' + d.tool_name || b.tool_name === d.tool_name);
                    const shadow = this.assistant.blocks.find(claims)
                        || (claims(this.current) ? this.current : null);
                    if (shadow) {
                        shadow._claimed = true;
                        // Remembered, not applied. The block is usually richer,
                        // but its arguments are raw delta text and can arrive
                        // truncated, unparsed or absent, and the recorder takes
                        // the frame's in exactly that case.
                        //
                        // Whether that is SAFE depends on counts that are not
                        // complete yet: with two parallel calls to one tool
                        // there is no way to tell which frame belongs to which
                        // block, and copying anyway swapped their arguments —
                        // live showing a destructive write against the wrong
                        // path, and changing on reload. So the copy waits for
                        // the same moment the recorder makes it.
                        // Capped like the other frame branch. Only one of the
                        // two had a ceiling, so a large frame that CLAIMED a
                        // block rendered in full live and truncated on reload.
                        shadow._frameParams = this.capParameters(d.parameters || {});
                        // Remember the frame's own id. A result keyed to it
                        // would otherwise find no block — the frame is dropped
                        // as a duplicate — and float loose, where the recorder
                        // carries it onto the call.
                        shadow._frameCallId = d.tool_call_id;
                        break;
                    }
                    // tool_call_id included so a later tool_result can find it.
                    this.assistant.blocks.push({
                        type: 'tool_call', tool_name: d.tool_name,
                        tool_call_id: d.tool_call_id, _fromFrame: true,
                        ...this.capParameters(d.parameters || {}),
                    });
                    break;
                }
                case 'tool_result': {
                    // Attach to the call it belongs to, so the output is shown
                    // under the tool that produced it rather than floating
                    // loose. Matched on the id the bridge carries on both.
                    // `!('result' in b)` matters: a second result for the same
                    // id would otherwise overwrite the first and lose it, where
                    // the recorder keeps both. Two results for one call should
                    // not happen — and when something that should not happen
                    // does, keeping the evidence beats discarding half of it.
                    const owner = d.tool_call_id && this.assistant.blocks.find(
                        (b) => b.type === 'tool_call' && !('result' in b)
                            && (b.tool_call_id === d.tool_call_id || b._frameCallId === d.tool_call_id));
                    if (owner) {
                        owner.result = d.result;
                        if (d.is_error !== undefined) owner.is_error = d.is_error;
                    } else {
                        this.assistant.blocks.push({
                            type: 'tool_result', tool_call_id: d.tool_call_id,
                            result: d.result, is_error: d.is_error,
                        });
                    }
                    break;
                }
                case 'rate_limit':
                    // Informational and non-terminal. Recorded on the element so
                    // a host app can read it; deliberately not drawn, since a
                    // quota notice mid-answer is not what the reader is here for.
                    this.s.rateLimit = { provider: d.provider, info: d.info };
                    break;
                case 'done': this.finish(); return;
                case 'error': this.s.error = d.message || d.code || 'Stream error'; this.finish(); return;
                case 'cancelled': this.finish(); return;
            }
            this.scheduleRender();
        }
        finish() {
            this._terminated = true;
            // A turn killed mid-arguments never sends block_stop, so decode
            // here too or the block keeps its raw text and renders as "called
            // with no arguments" — the confusion the raw text exists to prevent.
            this.closeToolBlock();
            this.reconcileFrameParams();
            this.clearWatchdog();
            this.detachStreamSource();
            this.s.streaming = false; this.s.pulse = false; this.current = null;
            this.loadConversations().then(() => this.renderAll());
            this.renderAll();
        }

        // Decode the argument JSON a tool block's deltas accumulated.
        //
        // The raw text is kept as a SIBLING field, never as a `_raw` key inside
        // parameters: a tool may genuinely take an argument called `_raw`, and a
        // sentinel that can appear in the data is the same mistake as reading
        // failure out of an "Error:" prefix.
        closeToolBlock() {
            const b = this.current;
            if (!b || b.type !== 'tool_call' || b.text === undefined) return;
            const raw = (b.text || '').trim();
            delete b.text;
            b.parameters = {};
            if (raw) {
                try {
                    const v = JSON.parse(raw);
                    // Capped like every other argument path. This one had no
                    // ceiling at all, so a well-formed 200KB Write block kept
                    // all of it live and 64KB of parameters_raw on reload —
                    // the one rule this area keeps insisting must not have two
                    // implementations.
                    // Gated on the RAW delta text, which is what the recorder
                    // measures — re-encoding first splits the two on any JSON
                    // that is not already in canonical compact form, such as
                    // whitespace padding or \uXXXX escapes.
                    if (v && typeof v === 'object' && !Array.isArray(v)) {
                        if (new TextEncoder().encode(raw).length > 65536) {
                            b.parameters = {};
                            b.parameters_raw = this.cutToBytes(raw, 65536);
                            b.parameters_truncated_bytes = new TextEncoder().encode(raw).length;
                        } else {
                            b.parameters = v;
                        }
                    }
                    else this.keepRawArguments(b, raw);
                } catch (e) { this.keepRawArguments(b, raw); }
            }
        }

        // Match ConversationRecorder's argument ceiling, so a large frame does
        // not render in full live and truncated after a reload — the same
        // stream giving two answers, which is the divergence this whole area
        // keeps having to close. In BYOK and Managed modes EVERY tool call is a
        // frame, so this is the common path there, not an edge.
        capParameters(params) {
            let encoded;
            try { encoded = JSON.stringify(params); } catch (e) { encoded = undefined; }
            if (encoded === undefined) {
                return { parameters: {}, parameters_raw: '[arguments could not be encoded]' };
            }
            const bytes = new TextEncoder().encode(encoded).length;
            if (bytes <= 65536) return { parameters: params };
            return {
                parameters: {},
                // Cut by BYTES, like the recorder's mb_strcut. Slicing by
                // UTF-16 units instead made the two disagree by 6x on how much
                // they kept and 3x on the size they reported, for the same
                // input — and the corpus could not see it, because its one
                // large scenario was pure ASCII, where the units coincide.
                parameters_raw: this.cutToBytes(encoded, 65536),
                parameters_truncated_bytes: bytes,
            };
        }

        // Keep unparsed argument text under the same ceiling the recorder uses.
        // This path had no bound at all, so a truncated call rendered its whole
        // payload live and a 64KB slice of it after a reload.
        keepRawArguments(b, raw) {
            const bytes = new TextEncoder().encode(raw).length;
            if (bytes <= 65536) { b.parameters_raw = raw; return; }
            b.parameters_raw = this.cutToBytes(raw, 65536);
            b.parameters_truncated_bytes = bytes;
        }

        // Cut a string to a byte budget without splitting a character.
        cutToBytes(text, budget) {
            const encoder = new TextEncoder();
            if (encoder.encode(text).length <= budget) return text;
            let low = 0;
            let high = text.length;
            while (low < high) {
                const mid = Math.ceil((low + high) / 2);
                // Never end on a high surrogate: half a pair is not a character.
                const code = text.charCodeAt(mid - 1);
                const end = (code >= 0xd800 && code <= 0xdbff) ? mid - 1 : mid;
                if (encoder.encode(text.slice(0, end)).length <= budget) low = mid;
                else high = mid - 1;
            }
            const code = text.charCodeAt(low - 1);
            return text.slice(0, (code >= 0xd800 && code <= 0xdbff) ? low - 1 : low);
        }

        // Fall back to a tool_call frame's parsed arguments where the block's
        // own are unusable — the same rule, and the same guard, as
        // ConversationRecorder::reconcileToolCalls. Run once the turn ends,
        // because judging ambiguity needs counts that are only complete then.
        //
        // Ambiguity matters: with two parallel calls to one tool there is no
        // way to tell which frame belongs to which block, and this file's PHP
        // counterpart refuses the same guess about tool_results. Where it is
        // ambiguous nothing moves and each block keeps what it has.
        reconcileFrameParams() {
            const names = this._frameNames || [];
            const blocks = (this.assistant && this.assistant.blocks) || [];

            for (const b of blocks) {
                if (b.type !== 'tool_call' || b._frameParams === undefined) continue;

                const own = b.parameters;
                const usable = own && typeof own === 'object' && Object.keys(own).length > 0
                    && b.parameters_raw === undefined;

                // The bare name this block corresponds to, which is what a
                // frame carries.
                const logical = String(b.tool_name).indexOf('mcp__bridge__') === 0
                    ? String(b.tool_name).slice('mcp__bridge__'.length)
                    : String(b.tool_name);

                const frameCount = names.filter((n) => n === logical).length;
                const streamCount = blocks.filter((o) => o.type === 'tool_call' && o._fromStream
                    && (o.tool_name === 'mcp__bridge__' + logical || o.tool_name === logical)).length;

                const unambiguous = frameCount === 1 && streamCount === 1;

                const framed = b._frameParams;
                const hasFrameArgs = framed
                    && (Object.keys(framed.parameters || {}).length > 0 || framed.parameters_raw !== undefined);

                if (!usable && unambiguous && hasFrameArgs) {
                    b.parameters = framed.parameters || {};
                    delete b.parameters_raw;
                    delete b.parameters_truncated_bytes;
                    if (framed.parameters_raw !== undefined) b.parameters_raw = framed.parameters_raw;
                    if (framed.parameters_truncated_bytes !== undefined) {
                        b.parameters_truncated_bytes = framed.parameters_truncated_bytes;
                    }
                }
                delete b._frameParams;
            }

            this._frameNames = [];
        }

        scrollDown(stick = true, keepTop = 0) {
            // Follow the stream only while the reader is already at the bottom.
            // Pinning unconditionally drags a reader who scrolled up back down
            // on the next chunk — survivable at 3 renders per answer, unusable
            // at 35.
            //
            // Restoring is NOT optional in the other branch. innerHTML re-made
            // the scroller, so its scrollTop is 0: doing nothing here does not
            // leave the reader where they were, it throws them to the very top
            // of the conversation — worse than the pinning this replaced.
            // Raw scrollTop is the right thing to restore, because during a
            // stream the transcript grows below the viewport.
            const m = this.root.querySelector('.messages');
            if (m) m.scrollTop = stick ? m.scrollHeight : keepTop;
            // (re)bind inputs after each full render
            this.bindFormInputs();
        }
    }

    if (!customElements.get('ai-bridge-chat')) {
        customElements.define('ai-bridge-chat', AiBridgeChat);
    }
})();
