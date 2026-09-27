<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\Employee $me */

use yii\helpers\Html;

$this->title = 'Home';
?>
<div class="py-4">
    <h1 class="h3 fw-bold">Signed in as <?= Html::encode($me->full_name) ?></h1>
    <table class="table table-sm w-auto">
        <tr><th>Email</th><td><?= Html::encode($me->email) ?></td></tr>
        <tr><th>Role</th><td><span class="badge role-badge role-<?= Html::encode($me->role) ?>"><?= Html::encode($me->getRoleLabel()) ?></span></td></tr>
        <tr><th>Department</th><td><?= Html::encode($me->department->name ?? '-') ?></td></tr>
        <tr><th>Designation</th><td><?= Html::encode($me->designation) ?></td></tr>
    </table>
</div>
