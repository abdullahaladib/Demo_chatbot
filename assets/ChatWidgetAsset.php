<?php

declare(strict_types=1);

namespace app\assets;

use yii\web\AssetBundle;

/**
 * The floating chat widget (views/layouts/_chat_widget.php).
 */
class ChatWidgetAsset extends AssetBundle
{
    public $basePath = '@webroot';
    public $baseUrl = '@web';
    public $css = [
        'css/chat-widget.css',
    ];
    public $js = [
        'js/chat-widget.js',
    ];
    public $depends = [
        AppAsset::class, // yii.js (CSRF token) + Bootstrap styles
    ];
}
