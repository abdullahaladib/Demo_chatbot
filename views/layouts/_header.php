<?php

declare(strict_types=1);

/** @var yii\web\View $this */

use app\models\Employee;
use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;
use yii\helpers\Html;

/** @var Employee|null $me */
$me = Yii::$app->user->identity;

$items = [
    ['label' => 'Dashboard', 'url' => ['/chat/index'], 'visible' => $me !== null],
    ['label' => 'Security test bench', 'url' => ['/security-test/index'], 'visible' => $me !== null],
    ['label' => 'Audit log', 'url' => ['/chat/audit'], 'visible' => $me !== null],
];

// Demo role switcher: two clicks to become any seeded employee.
$switchItems = [];
if (!empty(Yii::$app->params['demoRoleSwitcher'])) {
    foreach (Employee::find()->where(['status' => 'active'])->orderBy('id')->all() as $u) {
        $switchItems[] = [
            'label' => Html::encode($u->full_name)
                . ' <span class="badge role-badge role-' . Html::encode($u->role) . '">'
                . Html::encode($u->getRoleLabel()) . '</span>',
            'url' => ['/site/switch-user'],
            'active' => $me !== null && $me->id === $u->id,
            'linkOptions' => ['data-method' => 'post', 'data-params' => ['id' => $u->id]],
        ];
    }
}

$right = [];
if ($switchItems) {
    $right[] = ['label' => 'Switch user', 'items' => $switchItems, 'dropdownOptions' => ['class' => 'dropdown-menu-end']];
}
if ($me !== null) {
    $right[] = [
        'label' => 'Sign out',
        'url' => ['/site/logout'],
        'linkOptions' => ['data-method' => 'post', 'class' => 'nav-link logout'],
    ];
} else {
    $right[] = ['label' => 'Sign in', 'url' => ['/site/login']];
}
?>
<header id="header">
    <?php NavBar::begin([
        'brandLabel' => Html::encode(Yii::$app->name),
        'brandUrl' => Yii::$app->homeUrl,
        'options' => ['class' => 'navbar-expand-md navbar-dark bg-dark fixed-top'],
    ]) ?>
    <?= Nav::widget(['options' => ['class' => 'navbar-nav me-auto'], 'items' => $items]) ?>
    <?php if ($me !== null): ?>
        <span class="navbar-text me-3 small" id="current-identity">
            <?= Html::encode($me->full_name) ?>
            <span class="badge role-badge role-<?= Html::encode($me->role) ?>"><?= Html::encode($me->getRoleLabel()) ?></span>
        </span>
    <?php endif ?>
    <?= Nav::widget(['options' => ['class' => 'navbar-nav'], 'encodeLabels' => false, 'items' => $right]) ?>
    <?= Html::button('&#127769;', [
        'id' => 'theme-toggle',
        'class' => 'btn btn-link nav-link fs-5 ms-2',
        'aria-label' => 'Switch to dark mode',
    ]) ?>
    <?php NavBar::end() ?>
</header>
