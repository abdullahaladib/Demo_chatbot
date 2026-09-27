<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Employee $me */
/** @var string $sql */
/** @var array|null $result */
/** @var string $prompt */
/** @var array<string,string> $presets */

use app\components\ai\AccessMap;
use yii\helpers\Html;

$this->title = 'Security test bench';
?>
<div class="py-3">
    <h1 class="h3 fw-bold mb-1"><?= Html::encode($this->title) ?></h1>
    <p class="text-body-secondary">
        Hand-written SQL through the exact same path the AI uses: validator &rarr; <code>dbAi</code> (erp_ai_ro)
        with <code>:me</code>/<code>:dept</code> bound from your session. No AI involved.
        Running as <strong><?= Html::encode($me->full_name) ?></strong>
        <span class="badge role-badge role-<?= Html::encode($me->role) ?>"><?= Html::encode($me->getRoleLabel()) ?></span>.
    </p>

    <div class="row g-4">
        <div class="col-lg-8">
            <?= Html::beginForm(['security-test/index'], 'post', ['id' => 'bench-form']) ?>
            <textarea name="sql" id="bench-sql" rows="6" class="form-control font-monospace mb-2"
                      placeholder="SELECT ... FROM v_my_leave_balance WHERE employee_id = :me"><?= Html::encode($sql) ?></textarea>
            <button class="btn btn-primary">Validate &amp; run</button>
            <?= Html::endForm() ?>

            <?php if ($result !== null): ?>
                <?php
                $cls = ['ok' => 'success', 'denied' => 'danger', 'error' => 'warning'][$result['status']];
                $label = ['ok' => 'ALLOWED', 'denied' => 'REFUSED', 'error' => 'FAILED'][$result['status']];
                ?>
                <div class="card border-<?= $cls ?> mt-4">
                    <div class="card-header bg-<?= $cls ?>-subtle">
                        <strong class="text-<?= $cls ?>"><?= $label ?></strong>
                        <?php if ($result['status'] !== 'ok'): ?>
                            &mdash; <?= Html::encode($result['userMessage']) ?>
                        <?php else: ?>
                            &mdash; <?= count($result['rows']) ?> row(s)
                        <?php endif ?>
                    </div>
                    <div class="card-body small">
                        <?php if ($result['reason'] !== ''): ?>
                            <div class="mb-2"><span class="text-body-secondary">Audit reason (not shown to chat users):</span>
                                <code><?= Html::encode($result['code']) ?></code> &ndash; <?= Html::encode($result['reason']) ?></div>
                        <?php endif ?>
                        <?php if ($result['finalSql'] !== ''): ?>
                            <div class="text-body-secondary">SQL actually executed on <code>dbAi</code>:</div>
                            <pre class="bg-body-tertiary p-2 rounded sql-block"><?= Html::encode($result['finalSql']) ?></pre>
                            <?php if ($result['bindings']): ?>
                                <div class="mb-2">Bound from session:
                                    <?php foreach ($result['bindings'] as $k => $v): ?>
                                        <code>:<?= Html::encode($k) ?> = <?= Html::encode((string) $v) ?></code>
                                    <?php endforeach ?>
                                </div>
                            <?php endif ?>
                            <?php if ($result['notes']): ?>
                                <ul class="mb-2"><?php foreach ($result['notes'] as $n): ?><li><?= Html::encode($n) ?></li><?php endforeach ?></ul>
                            <?php endif ?>
                        <?php endif ?>
                        <?php if ($result['rows']): ?>
                            <div class="table-responsive" style="max-height: 24rem">
                                <table class="table table-sm table-striped mb-0">
                                    <thead><tr><?php foreach (array_keys($result['rows'][0]) as $col): ?><th><?= Html::encode($col) ?></th><?php endforeach ?></tr></thead>
                                    <tbody>
                                    <?php foreach ($result['rows'] as $row): ?>
                                        <tr><?php foreach ($row as $v): ?><td><?= Html::encode((string) $v) ?></td><?php endforeach ?></tr>
                                    <?php endforeach ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif ?>
                    </div>
                </div>
            <?php endif ?>
        </div>

        <div class="col-lg-4">
            <h2 class="h6 fw-bold">Presets</h2>
            <div class="list-group small mb-4">
                <?php foreach ($presets as $label => $presetSql): ?>
                    <button type="button" class="list-group-item list-group-item-action preset"
                            data-sql="<?= Html::encode($presetSql) ?>"><?= Html::encode($label) ?></button>
                <?php endforeach ?>
            </div>

            <h2 class="h6 fw-bold">Views allowed for <code><?= Html::encode($me->role) ?></code></h2>
            <ul class="small font-monospace">
                <?php foreach (AccessMap::viewsFor($me->role) as $v): $cfg = AccessMap::view($v); ?>
                    <li><?= Html::encode($v) ?><?php if (!empty($cfg['scope'])): ?>
                        <span class="text-body-secondary">(<?= Html::encode("{$cfg['column']} = :{$cfg['scope']}") ?>)</span><?php endif ?></li>
                <?php endforeach ?>
            </ul>
        </div>
    </div>

    <details class="mt-4">
        <summary class="fw-semibold">System prompt the AI receives for this role</summary>
        <pre class="bg-body-tertiary p-3 rounded small mt-2 sql-block"><?= Html::encode($prompt) ?></pre>
    </details>
</div>
<?php
$this->registerJs(<<<JS
document.querySelectorAll('.preset').forEach(b => b.addEventListener('click', () => {
    document.getElementById('bench-sql').value = b.dataset.sql;
}));
JS);
