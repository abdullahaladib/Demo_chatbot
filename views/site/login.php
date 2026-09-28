<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\LoginForm $model */

use app\models\Employee;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

$this->title = 'Sign in';
$demoUsers = empty(Yii::$app->params['demoRoleSwitcher'])
    ? []
    : Employee::demoPeople();
?>
<div class="row justify-content-center py-4">
    <div class="col-md-5 mb-4">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h1 class="h4 fw-bold mb-3"><?= Html::encode($this->title) ?></h1>

                <?php $form = ActiveForm::begin(['id' => 'login-form']); ?>
                <?= $form->field($model, 'username')->textInput(['autofocus' => true, 'placeholder' => 'tanvir']) ?>
                <?= $form->field($model, 'password')->passwordInput() ?>
                <div class="d-grid">
                    <?= Html::submitButton('Sign in', ['class' => 'btn btn-primary', 'name' => 'login-button']) ?>
                </div>
                <?php ActiveForm::end(); ?>

                <p class="text-body-secondary small mt-3 mb-0">
                    All demo accounts use the password <code>Demo@1234</code>.
                </p>
            </div>
        </div>
    </div>

    <?php if ($demoUsers): ?>
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3">Demo: sign in as&hellip;</h2>
                <div class="list-group list-group-flush">
                    <?php foreach ($demoUsers as $u): ?>
                        <?= Html::a(
                            '<span><strong>' . Html::encode($u->full_name) . '</strong>'
                            . ' <span class="text-body-secondary small">' . Html::encode($u->erpUser->username ?? '') . '</span></span>'
                            . '<span><span class="badge role-badge role-' . Html::encode($u->role) . '">'
                            . Html::encode($u->getRoleLabel()) . '</span> '
                            . '<span class="text-body-secondary small">' . Html::encode($u->department->name ?? '') . '</span></span>',
                            ['site/switch-user'],
                            [
                                'class' => 'list-group-item list-group-item-action d-flex justify-content-between align-items-center',
                                'data-method' => 'post',
                                'data-params' => ['id' => $u->id],
                            ],
                        ) ?>
                    <?php endforeach ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif ?>
</div>
