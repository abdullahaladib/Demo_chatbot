<?php

declare(strict_types=1);

/**
 * Dashboard: the page behind the floating chat widget. The chat itself is rendered
 * on every signed-in page by views/layouts/_chat_widget.php.
 *
 * @var yii\web\View $this
 * @var app\models\Employee $me
 */

use yii\helpers\Html;

$this->title = 'Demo Chatbot Testing Interface';
?>
<div class="py-4">
    <div class="demo-hero p-4 p-md-5 mb-4">
        <div class="small text-uppercase fw-semibold mb-2" style="letter-spacing:.08em; opacity:.85">Demo environment &middot; copy of the training ERP database</div>
        <h1 class="display-6 fw-bold mb-3">Demo Chatbot Testing Interface</h1>
        <p class="lead mb-0">
            This page is only a backdrop for testing the ERP Assistant. Click the
            <strong>chat button in the bottom-right corner</strong> to open it.
        </p>
    </div>

    <div class="row g-3">
        <div class="col-md-5">
            <div class="demo-card p-3">
                <h2 class="h6 fw-bold mb-3">You are signed in as</h2>
                <div class="fs-5 fw-semibold" id="dashboard-name"><?= Html::encode($me->full_name) ?></div>
                <div class="mb-2">
                    <span class="badge role-badge role-<?= Html::encode($me->role) ?>"><?= Html::encode($me->getRoleLabel()) ?></span>
                    <span class="text-body-secondary small ms-1"><?= Html::encode($me->designation) ?> &middot; <?= Html::encode($me->department->name ?? '-') ?></span>
                </div>
                <p class="small text-body-secondary mb-0">
                    The assistant only sees what the <strong><?= Html::encode($me->getRoleLabel()) ?></strong> role is allowed to see.
                    Use <strong>Switch user</strong> (top right) to test another role; each user gets a fresh conversation.
                </p>
            </div>
        </div>
        <div class="col-md-7">
            <div class="demo-card p-3">
                <h2 class="h6 fw-bold mb-3">What this demo proves</h2>
                <div class="d-flex gap-2 mb-2"><span class="demo-step-num">1</span>
                    <div class="small">The AI writes SQL itself, but it can only run against <strong>role-scoped, read-only views</strong>.</div></div>
                <div class="d-flex gap-2 mb-2"><span class="demo-step-num">2</span>
                    <div class="small">Whose rows you see is bound from your <strong>login session</strong>, never from the AI.</div></div>
                <div class="d-flex gap-2 mb-2"><span class="demo-step-num">3</span>
                    <div class="small">Ask something your role can't see (for example salaries as an employee) and it is <strong>refused</strong>.</div></div>
                <div class="d-flex gap-2"><span class="demo-step-num">4</span>
                    <div class="small">Turn on <strong>SQL</strong> in the chat header to see the generated and executed SQL.</div></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="demo-card p-3">
                <h2 class="h6 fw-bold mb-1"><?= Html::a('Security test bench', ['/security-test/index']) ?></h2>
                <p class="small text-body-secondary mb-0">Run hand-written SQL through the same permission layer, with no AI involved, and see the exact system prompt for your role.</p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="demo-card p-3">
                <h2 class="h6 fw-bold mb-1"><?= Html::a('Audit log', ['/chat/audit']) ?></h2>
                <p class="small text-body-secondary mb-0">Every chat turn is recorded, including refusals and errors.</p>
            </div>
        </div>
    </div>
</div>
