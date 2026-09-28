<?php

declare(strict_types=1);

namespace app\commands;

use app\components\ai\ResponseCache;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * The AI response cache (components/ai/ResponseCache.php).
 *
 *   php yii ai-cache/clear     forget every cached answer (next questions call the AI again)
 */
class AiCacheController extends Controller
{
    public function actionClear(): int
    {
        $cache = ResponseCache::fromConfig();
        if (!$cache->enabled()) {
            // Clear the default location anyway, in case it was enabled earlier.
            (new ResponseCache(ResponseCache::fileCache(Yii::getAlias('@runtime/ai-cache'))))->clear();
        } else {
            $cache->clear();
        }
        $this->stdout("AI response cache cleared.\n");
        return ExitCode::OK;
    }
}
