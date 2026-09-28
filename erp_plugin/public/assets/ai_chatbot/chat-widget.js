/**
 * AI Chatbot plug-in - floating Messenger-style chat widget for the ERP.
 * Vanilla JS, no dependencies (does not rely on the ERP's jQuery), all ids prefixed `aic-`
 * so nothing collides with ERP page markup.
 *
 * - The bottom-right button toggles the popup; closing only hides it (conversation kept).
 * - History is kept in sessionStorage per ERP company + user, so it survives moving
 *   between ERP pages; another user's history is deleted on load.
 * - All model/server text is escaped before it is inserted (no raw HTML).
 */
(function () {
    'use strict';

    const root = document.getElementById('aic-widget');
    if (!root || root.dataset.ready) {
        return;
    }
    root.dataset.ready = '1';

    const cfg = JSON.parse(root.dataset.config);
    const $ = id => document.getElementById(id);
    const panel = $('aic-panel'), fab = $('aic-fab'), unread = $('aic-unread');
    const list = $('aic-messages'), form = $('aic-form'), input = $('aic-question'), send = $('aic-send');
    const toggleSql = $('aic-toggle-sql');

    const PREFIX = 'erp.aichat.v1.';
    const KEY = PREFIX + cfg.userKey;
    const MAX_MESSAGES = 50;

    // ------------------------------------------------------------------ storage
    const store = {
        get(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* unavailable */ } },
        remove(k) { try { sessionStorage.removeItem(k); } catch (e) { /* ignore */ } },
        keys() { try { return Object.keys(sessionStorage); } catch (e) { return []; } },
    };
    store.keys().filter(k => k.startsWith(PREFIX) && k !== KEY).forEach(store.remove);

    let state = {messages: [], open: false, expanded: false};
    try { state = Object.assign(state, JSON.parse(store.get(KEY) || '{}')); } catch (e) { /* start fresh */ }
    const save = () => {
        state.messages = state.messages.slice(-MAX_MESSAGES);
        store.set(KEY, JSON.stringify(state));
    };

    // ------------------------------------------------------------------ rendering
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    function md(text) {
        const lines = esc(text).split(/\n/);
        let html = '', open = null;
        const inline = s => s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/`([^`]+)`/g, '<code>$1</code>');
        for (const raw of lines) {
            const line = raw.trim().replace(/^#{1,6}\s+/, '');
            const ul = line.match(/^[-*•]\s+(.*)/), ol = line.match(/^\d+[.)]\s+(.*)/);
            const kind = ul ? 'ul' : ol ? 'ol' : null;
            if (kind) {
                if (open !== kind) { if (open) html += '</' + open + '>'; html += '<' + kind + '>'; open = kind; }
                html += '<li>' + inline((ul || ol)[1]) + '</li>';
                continue;
            }
            if (open) { html += '</' + open + '>'; open = null; }
            if (line !== '') html += '<p>' + inline(line) + '</p>';
        }
        if (open) html += '</' + open + '>';
        return html;
    }

    const isNum = v => typeof v === 'number' || /^-?\d+(\.\d+)?$/.test(v ?? '');
    function fmt(v, col) {
        if (v === null || v === undefined) return '<span class="aic-muted">-</span>';
        if (!/(^|_)(year|id|code|no)$/i.test(col) && isNum(v) && String(v).length < 16) {
            const n = Number(v);
            return esc(Number.isInteger(n) ? n.toLocaleString('en-US') : n.toLocaleString('en-US', {maximumFractionDigits: 2}));
        }
        return esc(v);
    }

    function table(t) {
        if (!t || !t.rows || !t.rows.length) return '';
        const cols = t.columns;
        const numeric = Object.fromEntries(cols.map(c => [c, t.rows.every(r => r[c] === null || isNum(r[c]))]));
        const head = cols.map(c => '<th' + (numeric[c] ? ' class="aic-num"' : '') + '>' + esc(c.replace(/_/g, ' ')) + '</th>').join('');
        const body = t.rows.slice(0, 200).map(r => '<tr>' + cols.map(c =>
            '<td' + (numeric[c] ? ' class="aic-num"' : '') + '>' + fmt(r[c], c) + '</td>').join('') + '</tr>').join('');
        return '<div class="aic-table"><table><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>'
            + '<div class="aic-rowcount">' + t.rows.length + ' row' + (t.rows.length === 1 ? '' : 's') + '</div>';
    }

    const PATH_LABEL = {info: 'INFO - knowledge base, no SQL', data: 'DATA - query ran', denied: 'REFUSED', error: 'ERROR'};
    function machinery(r) {
        let h = '<div class="aic-machinery">';
        h += '<div><span class="aic-path aic-path-' + esc(r.path) + '">' + esc(PATH_LABEL[r.path] || r.path) + '</span>'
            + ' <span class="aic-muted">' + esc(r.provider || '-') + ' / ' + esc(r.model || '-') + ' &middot; ' + esc(r.latencyMs) + ' ms</span></div>';
        if (r.trace && r.trace.length) {
            h += '<ol class="aic-trace">' + r.trace.map(t => '<li><code>' + esc(t.step) + '</code> &rarr; ' + esc(t.detail) + '</li>').join('') + '</ol>';
        }
        (r.queries || []).forEach((q, i) => {
            h += '<div class="aic-query"><b>Query ' + (i + 1) + ' - <span class="aic-q-' + esc(q.status) + '">'
                + esc(q.status === 'ok' ? 'allowed' : q.status) + '</span></b>'
                + (q.status !== 'ok' ? ' <span class="aic-muted">(' + esc(q.code) + ')</span>' : '');
            h += '<div class="aic-muted">Generated by the model:</div><pre>' + esc(q.sql) + '</pre>';
            if (q.executedSql) h += '<div class="aic-muted">Executed read-only after validation:</div><pre>' + esc(q.executedSql) + '</pre>';
            const b = Object.entries(q.bindings || {});
            if (b.length) h += '<div>Bound from your ERP session: ' + b.map(([k, v]) => '<code>:' + esc(k) + ' = ' + esc(v) + '</code>').join(' ') + '</div>';
            if (q.notes && q.notes.length) h += '<ul>' + q.notes.map(n => '<li>' + esc(n) + '</li>').join('') + '</ul>';
            h += '</div>';
        });
        if (r.path === 'info') h += '<div class="aic-muted">No database query was made.</div>';
        if (r.path === 'denied' && !(r.queries || []).length) h += '<div class="aic-muted">The model found no permitted table for this and generated no SQL.</div>';
        return h + '</div>';
    }

    const userHtml = text => '<div class="aic-msg aic-msg-user"><div class="aic-bubble">' + esc(text) + '</div></div>';
    function assistantHtml(r) {
        const cls = r.path === 'denied' ? ' aic-denied' : r.path === 'error' ? ' aic-error' : '';
        const icon = r.path === 'denied' ? '<i class="fa-solid fa-lock"></i> ' : '';
        return '<div class="aic-msg aic-msg-bot"><div class="aic-bubble' + cls + '">' + icon + md(r.answer) + table(r.table) + machinery(r) + '</div></div>';
    }
    const greetingHtml = () => '<div class="aic-msg aic-msg-bot"><div class="aic-bubble">Hi ' + esc(cfg.firstName)
        + '. Ask me about company policy, or about any ERP data your modules give you access to '
        + '(<strong>' + esc(cfg.modulesLabel) + '</strong>).</div></div>';

    const scrollToEnd = () => { list.scrollTop = list.scrollHeight; };
    function renderAll() {
        list.innerHTML = greetingHtml() + state.messages.map(m => m.role === 'user' ? userHtml(m.text) : assistantHtml(m.data)).join('');
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
        root.classList.toggle('aic-open', open);
        fab.setAttribute('aria-expanded', open ? 'true' : 'false');
        fab.setAttribute('aria-label', open ? 'Close ERP Assistant' : 'Open ERP Assistant');
        if (open) {
            unread.hidden = true;
            scrollToEnd();
            if (!input.disabled) input.focus();
        }
    }
    function setExpanded(expanded) {
        state.expanded = expanded;
        save();
        root.classList.toggle('aic-expanded', expanded);
    }

    fab.addEventListener('click', () => setOpen(panel.hidden));
    $('aic-close').addEventListener('click', () => setOpen(false));
    $('aic-expand').addEventListener('click', () => setExpanded(!state.expanded));
    $('aic-clear').addEventListener('click', () => { state.messages = []; save(); renderAll(); input.focus(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) setOpen(false); });

    try { toggleSql.checked = localStorage.getItem('erp.aichat.showSql') === '1'; } catch (e) { /* ignore */ }
    const applySql = () => root.classList.toggle('aic-show-sql', toggleSql.checked);
    toggleSql.addEventListener('change', () => {
        applySql();
        try { localStorage.setItem('erp.aichat.showSql', toggleSql.checked ? '1' : '0'); } catch (e) { /* ignore */ }
    });

    // ------------------------------------------------------------------ asking
    async function ask(q) {
        append({role: 'user', text: q});
        input.value = '';
        send.disabled = input.disabled = true;
        list.insertAdjacentHTML('beforeend', '<div class="aic-msg aic-msg-bot" id="aic-typing"><div class="aic-bubble aic-typing"><span></span><span></span><span></span></div></div>');
        scrollToEnd();

        let data;
        try {
            const res = await fetch(cfg.askUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': cfg.csrf},
                body: JSON.stringify({question: q}),
                credentials: 'same-origin',
            });
            try {
                data = await res.json();
            } catch (e) { // the server answered, but not with JSON (a PHP error page)
                data = {answer: 'The assistant hit a server error (HTTP ' + res.status + '). Please try again.', path: 'error', latencyMs: 0, queries: [], trace: []};
            }
        } catch (e) {
            data = {answer: 'Could not reach the ERP server. Please check your connection and try again.', path: 'error', latencyMs: 0, queries: [], trace: []};
        } finally {
            $('aic-typing')?.remove();
            send.disabled = input.disabled = false;
        }

        append({role: 'assistant', data});
        if (panel.hidden) {
            unread.hidden = false;
        } else {
            input.focus();
        }
    }

    form.addEventListener('submit', e => {
        e.preventDefault();
        const q = input.value.trim();
        if (q && !input.disabled) ask(q);
    });
    root.querySelectorAll('.aic-chip').forEach(b => b.addEventListener('click', () => {
        if (!input.disabled) ask(b.textContent.trim());
    }));

    // ------------------------------------------------------------------ boot
    applySql();
    setExpanded(state.expanded);
    renderAll();
    setOpen(state.open);
})();
