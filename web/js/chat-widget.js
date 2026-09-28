/**
 * Floating chat widget (Messenger-style popup).
 *
 * - The bottom-right button toggles the popup. Closing only hides it, so the
 *   conversation stays in the page.
 * - History is also kept in sessionStorage, keyed by the signed-in user's id, so it
 *   survives page changes and reloads within the tab. Another user's history is
 *   deleted on load, so switching users never shows the previous user's conversation.
 * - All rendering escapes model/server text before inserting it (no raw HTML).
 */
(function () {
    'use strict';

    const root = document.getElementById('chat-widget');
    if (!root) {
        return;
    }

    const cfg = JSON.parse(root.dataset.config);
    const $ = id => document.getElementById(id);
    const panel = $('chat-panel'), fab = $('chat-fab'), unread = $('chat-unread');
    const list = $('messages'), form = $('ask-form'), input = $('question'), send = $('send');
    const toggleSql = $('toggle-sql');

    const PREFIX = 'erp.chat.v1.';
    const KEY = PREFIX + cfg.userId;
    const MAX_MESSAGES = 50;

    // ------------------------------------------------------------------ storage

    const store = {
        get(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* storage unavailable */ } },
        remove(k) { try { sessionStorage.removeItem(k); } catch (e) { /* ignore */ } },
        keys() { try { return Object.keys(sessionStorage); } catch (e) { return []; } },
    };

    // Drop any other user's conversation (e.g. after "Switch user").
    store.keys().filter(k => k.startsWith(PREFIX) && k !== KEY).forEach(store.remove);

    let state = {messages: [], open: false, expanded: false};
    try {
        state = Object.assign(state, JSON.parse(store.get(KEY) || '{}'));
    } catch (e) { /* corrupt entry: start fresh */ }

    const save = () => {
        state.messages = state.messages.slice(-MAX_MESSAGES);
        store.set(KEY, JSON.stringify(state));
    };

    // ------------------------------------------------------------------ rendering

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    // Minimal, safe markdown: escape first, then **bold**, `code`, bullet/numbered lists, paragraphs.
    function md(text) {
        const lines = esc(text).split(/\n/);
        let html = '', open = null;
        const inline = s => s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/`([^`]+)`/g, '<code>$1</code>');
        for (const raw of lines) {
            const line = raw.trim().replace(/^#{1,6}\s+/, '');
            const ul = line.match(/^[-*•]\s+(.*)/), ol = line.match(/^\d+[.)]\s+(.*)/);
            const kind = ul ? 'ul' : ol ? 'ol' : null;
            if (kind) {
                if (open !== kind) {
                    if (open) html += '</' + open + '>';
                    html += '<' + kind + '>';
                    open = kind;
                }
                html += '<li>' + inline((ul || ol)[1]) + '</li>';
                continue;
            }
            if (open) {
                html += '</' + open + '>';
                open = null;
            }
            if (line !== '') html += '<p>' + inline(line) + '</p>';
        }
        if (open) html += '</' + open + '>';
        return html;
    }

    const isNum = v => typeof v === 'number' || /^-?\d+(\.\d+)?$/.test(v ?? '');

    function fmt(v, col) {
        if (v === null || v === undefined) return '<span class="text-body-secondary">-</span>';
        if (!/(^|_)(year|id|code)$/.test(col) && isNum(v) && String(v).length < 16) {
            const n = Number(v);
            return esc(Number.isInteger(n) ? n.toLocaleString('en-US') : n.toLocaleString('en-US', {maximumFractionDigits: 2}));
        }
        return esc(v);
    }

    function table(t) {
        if (!t || !t.rows || !t.rows.length) return '';
        const cols = t.columns;
        const numeric = Object.fromEntries(cols.map(c => [c, t.rows.every(r => r[c] === null || isNum(r[c]))]));
        const head = cols.map(c => '<th' + (numeric[c] ? ' class="text-end"' : '') + '>' + esc(c.replace(/_/g, ' ')) + '</th>').join('');
        const body = t.rows.slice(0, 200).map(r => '<tr>' + cols.map(c =>
            '<td' + (numeric[c] ? ' class="text-end"' : '') + '>' + fmt(r[c], c) + '</td>'
        ).join('') + '</tr>').join('');
        return '<div class="result-table mt-2"><table class="table table-sm table-striped mb-0">'
            + '<thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>'
            + '<div class="small text-body-secondary mt-1">' + t.rows.length + ' row' + (t.rows.length === 1 ? '' : 's') + '</div>';
    }

    const PATH_LABEL = {info: 'INFO - knowledge base, no SQL', data: 'DATA - query ran', denied: 'REFUSED', error: 'ERROR'};

    function machinery(r) {
        let h = '<div class="machinery mt-2">';
        h += '<div class="mb-1"><span class="badge path-badge path-' + esc(r.path) + '">' + esc(PATH_LABEL[r.path] || r.path) + '</span>'
            + ' <span class="text-body-secondary">' + esc(r.provider || '-') + ' / ' + esc(r.model || '-') + ' &middot; ' + esc(r.latencyMs) + ' ms</span></div>';
        if (r.trace && r.trace.length) {
            h += '<ol class="trace mb-1">' + r.trace.map(t => '<li><code>' + esc(t.step) + '</code> &rarr; ' + esc(t.detail) + '</li>').join('') + '</ol>';
        }
        (r.queries || []).forEach((q, i) => {
            h += '<div class="query-block"><div class="fw-semibold">Query ' + (i + 1) + ' - '
                + '<span class="q-' + esc(q.status) + '">' + esc(q.status === 'ok' ? 'allowed' : q.status) + '</span>'
                + (q.status !== 'ok' ? ' <span class="text-body-secondary">(' + esc(q.code) + ')</span>' : '') + '</div>';
            h += '<div class="text-body-secondary">Generated by the model:</div><pre class="sql-block">' + esc(q.sql) + '</pre>';
            if (q.executedSql) {
                h += '<div class="text-body-secondary">Executed on dbAi (erp_ai_ro) after validation:</div><pre class="sql-block">' + esc(q.executedSql) + '</pre>';
            }
            const b = Object.entries(q.bindings || {});
            if (b.length) h += '<div>Bound from session: ' + b.map(([k, v]) => '<code>:' + esc(k) + ' = ' + esc(v) + '</code>').join(' ') + '</div>';
            if (q.notes && q.notes.length) h += '<ul class="mb-0">' + q.notes.map(n => '<li>' + esc(n) + '</li>').join('') + '</ul>';
            h += '</div>';
        });
        if (r.path === 'info') h += '<div class="text-body-secondary">No database query was made.</div>';
        if (r.path === 'denied' && !(r.queries || []).length) h += '<div class="text-body-secondary">The model found no permitted view for this and generated no SQL.</div>';
        return h + '</div>';
    }

    function userHtml(text) {
        return '<div class="msg msg-user"><div class="bubble">' + esc(text) + '</div></div>';
    }

    function assistantHtml(r) {
        const cls = r.path === 'denied' ? ' bubble-denied' : r.path === 'error' ? ' bubble-error' : '';
        const icon = r.path === 'denied' ? '<span class="deny-icon" aria-hidden="true">&#128274;</span> ' : '';
        return '<div class="msg msg-assistant"><div class="bubble' + cls + '">' + icon + md(r.answer) + table(r.table) + machinery(r) + '</div></div>';
    }

    function greetingHtml() {
        return '<div class="msg msg-assistant"><div class="bubble">Hi ' + esc(cfg.firstName)
            + '. Ask me about company policy, or about ERP data your role (<strong>' + esc(cfg.roleLabel)
            + '</strong>) is allowed to see.</div></div>';
    }

    const scrollToEnd = () => { list.scrollTop = list.scrollHeight; };

    function renderAll() {
        list.innerHTML = greetingHtml() + state.messages
            .map(m => m.role === 'user' ? userHtml(m.text) : assistantHtml(m.data))
            .join('');
        scrollToEnd();
    }

    function append(message) {
        state.messages.push(message);
        save();
        list.insertAdjacentHTML('beforeend', message.role === 'user' ? userHtml(message.text) : assistantHtml(message.data));
        scrollToEnd();
    }

    // ------------------------------------------------------------------ open / close / expand

    function setOpen(open) {
        state.open = open;
        save();
        panel.hidden = !open;
        root.classList.toggle('is-open', open);
        fab.setAttribute('aria-expanded', open ? 'true' : 'false');
        fab.setAttribute('aria-label', open ? 'Close chat' : 'Open chat');
        if (open) {
            unread.hidden = true;
            scrollToEnd();
            if (!input.disabled) input.focus();
        }
    }

    function setExpanded(expanded) {
        state.expanded = expanded;
        save();
        root.classList.toggle('is-expanded', expanded);
        $('chat-expand').setAttribute('aria-label', expanded ? 'Shrink chat window' : 'Expand chat window');
    }

    fab.addEventListener('click', () => setOpen(panel.hidden));
    $('chat-close').addEventListener('click', () => setOpen(false));
    $('chat-expand').addEventListener('click', () => setExpanded(!state.expanded));
    $('chat-clear').addEventListener('click', () => {
        state.messages = [];
        save();
        renderAll();
        input.focus();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !panel.hidden) setOpen(false);
    });

    // "Show generated SQL" - remembered per browser.
    try { toggleSql.checked = localStorage.getItem('erp.showSql') === '1'; } catch (e) { /* ignore */ }
    const applySql = () => root.classList.toggle('show-sql', toggleSql.checked);
    toggleSql.addEventListener('change', () => {
        applySql();
        try { localStorage.setItem('erp.showSql', toggleSql.checked ? '1' : '0'); } catch (e) { /* ignore */ }
    });

    // ------------------------------------------------------------------ asking

    async function ask(q) {
        append({role: 'user', text: q});
        input.value = '';
        send.disabled = input.disabled = true;
        list.insertAdjacentHTML('beforeend', '<div class="msg msg-assistant" id="typing"><div class="bubble typing"><span></span><span></span><span></span></div></div>');
        scrollToEnd();

        let data;
        try {
            const res = await fetch(cfg.askUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': yii.getCsrfToken()},
                body: JSON.stringify({question: q}),
                credentials: 'same-origin',
            });
            if (res.status === 401) {
                window.location = cfg.loginUrl;
                return;
            }
            data = await res.json();
        } catch (e) {
            data = {answer: 'Could not reach the server. Please try again.', path: 'error', latencyMs: 0, queries: [], trace: []};
        } finally {
            $('typing')?.remove();
            send.disabled = input.disabled = false;
        }

        append({role: 'assistant', data});
        if (panel.hidden) {
            unread.hidden = false; // answer arrived while the popup was closed
        } else {
            input.focus();
        }
    }

    form.addEventListener('submit', e => {
        e.preventDefault();
        const q = input.value.trim();
        if (q && !input.disabled) ask(q);
    });
    document.querySelectorAll('#chat-widget .suggestion').forEach(b => b.addEventListener('click', () => {
        if (!input.disabled) ask(b.textContent.trim());
    }));

    // ------------------------------------------------------------------ boot

    applySql();
    setExpanded(state.expanded);
    renderAll();
    setOpen(state.open);
})();
