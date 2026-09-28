<?php

declare(strict_types=1);

/**
 * Floating chat widget (Messenger-style): a bottom-right button that toggles a popup.
 * Rendered by layouts/main.php on every page for signed-in users.
 * Behaviour lives in web/js/chat-widget.js, styles in web/css/chat-widget.css.
 *
 * @var yii\web\View $this
 * @var app\models\Employee $me
 */

use app\assets\ChatWidgetAsset;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

ChatWidgetAsset::register($this);

$suggestions = [
    'employee' => ["What's our leave policy?", 'How many casual leave days do I have left?', "What's the average salary in engineering?", 'How many days was I late in January 2026?'],
    'manager' => ['Who on my team has pending leave?', 'How many leave days do I have left?', "How was my team's attendance in January 2026?", "What's the average salary in engineering?"],
    'dept_head' => ['Who in my department has pending leave?', 'How many people are in my department?', 'Which leave type is used most in my department this year?', "What's the average salary in engineering?"],
    'hr' => ["What's the average salary in engineering?", 'Who has taken the most sick leave this year?', 'List all pending leave requests', 'Who joined most recently?'],
    'ceo' => ['Show total payroll by department', "What's the average salary in engineering?", 'Headcount by department', 'How many leave requests are pending company-wide?'],
][$me->role] ?? [];

$config = [
    'askUrl' => Url::to(['/chat/ask']),
    'loginUrl' => Url::to(['/site/login']),
    'userId' => (int) $me->id,
    'firstName' => explode(' ', $me->full_name)[0],
    'roleLabel' => $me->getRoleLabel(),
];
?>
<div id="chat-widget" class="chat-widget" data-config="<?= Html::encode(Json::encode($config)) ?>">

    <section id="chat-panel" class="chat-panel" role="dialog" aria-label="ERP Assistant chat" hidden>
        <header class="chat-panel-header">
            <div class="chat-avatar" aria-hidden="true">AI</div>
            <div class="chat-title">
                <div class="fw-semibold">ERP Assistant</div>
                <div class="chat-subtitle">
                    <?= Html::encode($me->full_name) ?>
                    <span class="badge role-badge role-<?= Html::encode($me->role) ?>" id="role-badge"><?= Html::encode($me->getRoleLabel()) ?></span>
                </div>
            </div>
            <div class="chat-actions">
                <label class="chat-sql-toggle" title="Show generated SQL">
                    <input type="checkbox" id="toggle-sql"> <span>SQL</span>
                </label>
                <button type="button" class="chat-icon-btn" id="chat-expand" title="Expand" aria-label="Expand chat window">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10V4h6M20 14v6h-6M4 4l6 6M20 20l-6-6" /></svg>
                </button>
                <button type="button" class="chat-icon-btn" id="chat-clear" title="Clear chat" aria-label="Clear chat history">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13" /></svg>
                </button>
                <button type="button" class="chat-icon-btn" id="chat-close" title="Close" aria-label="Close chat">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14" /></svg>
                </button>
            </div>
        </header>

        <div class="chat-messages" id="messages" aria-live="polite"></div>

        <footer class="chat-panel-footer">
            <?php if ($suggestions): ?>
                <div class="chat-suggestions" id="suggestions">
                    <?php foreach ($suggestions as $s): ?>
                        <button type="button" class="chat-chip suggestion"><?= Html::encode($s) ?></button>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
            <form id="ask-form" class="chat-form" autocomplete="off">
                <input type="text" id="question" class="form-control" maxlength="1000"
                       placeholder="Ask a question..." aria-label="Question">
                <button class="chat-send" id="send" aria-label="Send">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l18-8-8 18-2-8-8-2z" /></svg>
                </button>
            </form>
        </footer>
    </section>

    <button type="button" id="chat-fab" class="chat-fab" aria-controls="chat-panel" aria-expanded="false" aria-label="Open chat">
        <svg class="icon-chat" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z" /></svg>
        <svg class="icon-close" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
        <span class="chat-unread" id="chat-unread" hidden></span>
    </button>
</div>
