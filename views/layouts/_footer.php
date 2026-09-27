<?php

declare(strict_types=1);

/** @var yii\web\View $this */

use yii\helpers\Html;

?>
<footer id="footer" class="mt-auto py-3 bg-body-tertiary">
    <div class="container text-body-secondary small">
        <?= Html::encode(Yii::$app->name) ?> &middot; demo on fabricated data
    </div>
</footer>
