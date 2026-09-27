<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $provider */

use app\models\ChatAuditLog;
use yii\grid\GridView;
use yii\helpers\Html;

$this->title = 'Audit log';
/** @var app\models\Employee $me */
$me = Yii::$app->user->identity;
?>
<div class="py-3">
    <h1 class="h4 fw-bold mb-1"><?= Html::encode($this->title) ?></h1>
    <p class="text-body-secondary small">
        One row per chat turn - information answers, data answers, refusals and errors.
        <?= in_array($me->role, ['hr', 'ceo'], true) ? 'Showing all users.' : 'Showing your own questions only.' ?>
    </p>
    <?= GridView::widget([
        'dataProvider' => $provider,
        'tableOptions' => ['class' => 'table table-sm table-striped align-top small audit-table'],
        'columns' => [
            'id',
            ['attribute' => 'created_at', 'label' => 'When'],
            ['attribute' => 'employee_id', 'label' => 'Emp'],
            'role',
            [
                'attribute' => 'path',
                'format' => 'raw',
                'value' => fn(ChatAuditLog $m) => Html::tag('span', strtoupper($m->path), ['class' => 'badge path-badge path-' . $m->path]),
            ],
            ['attribute' => 'question', 'contentOptions' => ['style' => 'max-width: 22rem']],
            [
                'attribute' => 'generated_sql',
                'label' => 'Generated SQL',
                'format' => 'raw',
                'value' => fn(ChatAuditLog $m) => $m->generated_sql === null ? '' :
                    Html::tag('details', Html::tag('summary', 'show') . Html::tag('pre', Html::encode($m->generated_sql), ['class' => 'sql-block mb-0'])),
            ],
            ['attribute' => 'denial_reason', 'contentOptions' => ['style' => 'max-width: 18rem']],
            ['attribute' => 'row_count', 'label' => 'Rows'],
            ['attribute' => 'latency_ms', 'label' => 'ms'],
            [
                'label' => 'Provider / model',
                'value' => fn(ChatAuditLog $m) => trim(($m->provider ?? '') . ' ' . ($m->model ?? '')),
            ],
        ],
    ]) ?>
</div>
