<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Employee $me */

use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

$this->title = 'Chat';

$suggestions = [
    'employee' => ["What's our leave policy?", 'How many leave days do I have left?', "What's the average salary in engineering?", 'How many times was I late in the last month?'],
    'manager' => ['Who on my team has pending leave?', 'How many leave days do I have left?', "What's the average salary in engineering?", 'Who in my team was absent recently?'],
    'dept_head' => ['Who on my team has pending leave?', 'How many people are in my department?', "What's the average salary in engineering?", 'Which leave type is used most in my department this year?'],
    'hr' => ["What's the average salary in engineering?", 'Who has taken the most sick leave this year?', 'List all pending leave requests', 'Who joined most recently?'],
    'ceo' => ['Show total payroll by department', "What's the average salary in engineering?", 'Headcount by department', 'How many leave requests are pending company-wide?'],
][$me->role] ?? [];
?>
<div class="chat-page py-3">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <h1 class="h5 fw-bold mb-0 me-2">ERP Assistant</h1>
        <span class="text-body-secondary small">asking as</span>
        <strong class="small"><?= Html::encode($me->full_name) ?></strong>
        <span class="badge role-badge role-<?= Html::encode($me->role) ?>" id="role-badge"><?= Html::encode($me->getRoleLabel()) ?></span>
        <span class="text-body-secondary small"><?= Html::encode($me->department->name ?? '') ?></span>
        <div class="form-check form-switch ms-auto mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="toggle-sql">
            <label class="form-check-label small" for="toggle-sql">Show generated SQL</label>
        </div>
    </div>

    <div class="card chat-card shadow-sm">
        <div class="chat-messages" id="messages" aria-live="polite">
            <div class="msg msg-assistant">
                <div class="bubble">
                    Hi <?= Html::encode(explode(' ', $me->full_name)[0]) ?>. Ask me about company policy, or about ERP data
                    your role (<strong><?= Html::encode($me->getRoleLabel()) ?></strong>) is allowed to see.
                </div>
            </div>
        </div>
        <div class="chat-input border-top p-2">
            <?php if ($suggestions): ?>
                <div class="d-flex flex-wrap gap-1 mb-2" id="suggestions">
                    <?php foreach ($suggestions as $s): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary suggestion"><?= Html::encode($s) ?></button>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
            <form id="ask-form" class="d-flex gap-2" autocomplete="off">
                <input type="text" id="question" class="form-control" maxlength="1000"
                       placeholder="Ask a question..." aria-label="Question" autofocus>
                <button class="btn btn-primary px-4" id="send">Send</button>
            </form>
        </div>
    </div>
</div>
<?php
$askUrl = Json::htmlEncode(Url::to(['chat/ask']));
$loginUrl = Json::htmlEncode(Url::to(['site/login']));
$this->registerJs(<<<JS
(function () {
    const askUrl = $askUrl, loginUrl = $loginUrl;
    const list = document.getElementById('messages');
    const form = document.getElementById('ask-form');
    const input = document.getElementById('question');
    const send = document.getElementById('send');
    const toggle = document.getElementById('toggle-sql');

    // "Show generated SQL" - remembered per browser.
    try { toggle.checked = localStorage.getItem('erp.showSql') === '1'; } catch (e) {}
    const applyToggle = () => document.body.classList.toggle('show-sql', toggle.checked);
    toggle.addEventListener('change', () => {
        applyToggle();
        try { localStorage.setItem('erp.showSql', toggle.checked ? '1' : '0'); } catch (e) {}
    });
    applyToggle();

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    // Minimal, safe markdown: escape first, then **bold**, `code`, bullet/numbered lists, paragraphs.
    function md(text) {
        const lines = esc(text).split(/\\n/);
        let html = '', list = null;
        const inline = s => s.replace(/\\*\\*(.+?)\\*\\*/g, '<strong>$1</strong>').replace(/`([^`]+)`/g, '<code>$1</code>');
        for (const raw of lines) {
            const line = raw.trim();
            const ul = line.match(/^[-*•]\\s+(.*)/), ol = line.match(/^\\d+[.)]\\s+(.*)/);
            const kind = ul ? 'ul' : ol ? 'ol' : null;
            if (kind) {
                if (list !== kind) { if (list) html += '</' + list + '>'; html += '<' + kind + '>'; list = kind; }
                html += '<li>' + inline((ul || ol)[1]) + '</li>';
                continue;
            }
            if (list) { html += '</' + list + '>'; list = null; }
            if (line !== '') html += '<p>' + inline(line) + '</p>';
        }
        if (list) html += '</' + list + '>';
        return html;
    }

    const fmt = (v, col = '') => {
        if (v === null || v === undefined) return '<span class="text-body-secondary">-</span>';
        if (!/(^|_)(year|id|code)$/.test(col) && (typeof v === 'number' || (/^-?\d+(\.\d+)?$/.test(v) && String(v).length < 16))) {
            const n = Number(v);
            return esc(Number.isInteger(n) ? n.toLocaleString('en-US') : n.toLocaleString('en-US', {maximumFractionDigits: 2}));
        }
        return esc(v);
    };

    function table(t) {
        if (!t || !t.rows || !t.rows.length) return '';
        const cols = t.columns;
        const isNum = v => typeof v === 'number' || /^-?\\d+(\\.\\d+)?$/.test(v ?? '');
        const numeric = Object.fromEntries(cols.map(c => [c, t.rows.every(r => r[c] === null || isNum(r[c]))]));
        const head = cols.map(c => '<th' + (numeric[c] ? ' class="text-end"' : '') + '>' + esc(c.replace(/_/g, ' ')) + '</th>').join('');
        const body = t.rows.slice(0, 200).map(r => '<tr>' + cols.map(c =>
            '<td' + (numeric[c] ? ' class="text-end"' : '') + '>' + fmt(r[c], c) + '</td>'
        ).join('') + '</tr>').join('');
        return '<div class="result-table table-responsive mt-2"><table class="table table-sm table-striped mb-0">'
            + '<thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>'
            + '<div class="small text-body-secondary mt-1">' + t.rows.length + ' row' + (t.rows.length === 1 ? '' : 's') + '</div>';
    }

    const pathLabel = {info: 'INFO - knowledge base, no SQL', data: 'DATA - query ran', denied: 'REFUSED', error: 'ERROR'};

    function machinery(r) {
        let h = '<div class="machinery mt-2">';
        h += '<div class="mb-1"><span class="badge path-badge path-' + esc(r.path) + '">' + esc(pathLabel[r.path] || r.path) + '</span>'
          + ' <span class="text-body-secondary">' + esc(r.provider || '-') + ' / ' + esc(r.model || '-') + ' &middot; ' + r.latencyMs + ' ms</span></div>';
        if (r.trace && r.trace.length) {
            h += '<ol class="trace mb-1">' + r.trace.map(t => '<li><code>' + esc(t.step) + '</code> &rarr; ' + esc(t.detail) + '</li>').join('') + '</ol>';
        }
        (r.queries || []).forEach((q, i) => {
            h += '<div class="query-block"><div class="small fw-semibold">Query ' + (i + 1) + ' - '
               + '<span class="q-' + esc(q.status) + '">' + esc(q.status === 'ok' ? 'allowed' : q.status) + '</span>'
               + (q.status !== 'ok' ? ' <span class="text-body-secondary">(' + esc(q.code) + ')</span>' : '') + '</div>';
            h += '<div class="small text-body-secondary">Generated by the model:</div><pre class="sql-block">' + esc(q.sql) + '</pre>';
            if (q.executedSql) {
                h += '<div class="small text-body-secondary">Executed on dbAi (erp_ai_ro) after validation:</div><pre class="sql-block">' + esc(q.executedSql) + '</pre>';
            }
            const b = Object.entries(q.bindings || {});
            if (b.length) h += '<div class="small">Bound from session: ' + b.map(([k, v]) => '<code>:' + esc(k) + ' = ' + esc(v) + '</code>').join(' ') + '</div>';
            if (q.notes && q.notes.length) h += '<ul class="small mb-0">' + q.notes.map(n => '<li>' + esc(n) + '</li>').join('') + '</ul>';
            h += '</div>';
        });
        if (r.path === 'info') h += '<div class="small text-body-secondary">No database query was made.</div>';
        if (r.path === 'denied' && !(r.queries || []).length) h += '<div class="small text-body-secondary">The model found no permitted view for this and generated no SQL.</div>';
        return h + '</div>';
    }

    function addUser(text) {
        list.insertAdjacentHTML('beforeend', '<div class="msg msg-user"><div class="bubble">' + esc(text) + '</div></div>');
        list.scrollTop = list.scrollHeight;
    }

    function addAssistant(r) {
        const cls = r.path === 'denied' ? ' bubble-denied' : r.path === 'error' ? ' bubble-error' : '';
        const icon = r.path === 'denied' ? '<span class="deny-icon" aria-hidden="true">&#128274;</span> ' : '';
        list.insertAdjacentHTML('beforeend',
            '<div class="msg msg-assistant"><div class="bubble' + cls + '">' + icon + md(r.answer) + table(r.table) + machinery(r) + '</div></div>');
        list.scrollTop = list.scrollHeight;
    }

    async function ask(q) {
        addUser(q);
        input.value = '';
        send.disabled = input.disabled = true;
        list.insertAdjacentHTML('beforeend', '<div class="msg msg-assistant" id="typing"><div class="bubble typing"><span></span><span></span><span></span></div></div>');
        list.scrollTop = list.scrollHeight;
        try {
            const res = await fetch(askUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': yii.getCsrfToken()},
                body: JSON.stringify({question: q}),
                credentials: 'same-origin',
            });
            if (res.status === 401) { window.location = loginUrl; return; }
            const data = await res.json();
            addAssistant(data);
        } catch (e) {
            addAssistant({answer: 'Could not reach the server. Please try again.', path: 'error', latencyMs: 0, queries: [], trace: []});
        } finally {
            document.getElementById('typing')?.remove();
            send.disabled = input.disabled = false;
            input.focus();
        }
    }

    form.addEventListener('submit', e => {
        e.preventDefault();
        const q = input.value.trim();
        if (q) ask(q);
    });
    document.querySelectorAll('.suggestion').forEach(b => b.addEventListener('click', () => ask(b.textContent.trim())));
})();
JS);
