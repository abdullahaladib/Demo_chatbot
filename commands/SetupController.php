<?php

declare(strict_types=1);

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * One-command database setup for a fresh machine.
 *
 *   php yii setup/database <mysql-root-password>
 *
 * 1. runs sql/00-bootstrap.sql as root (database + erp_app), with the password from config/db-local.php
 * 2. runs `yii migrate` (schema, seed, views)
 * 3. runs sql/01-ai-readonly-user.sql as root (erp_ai_ro, SELECT on views only)
 *
 * Safe to re-run: the bootstrap is idempotent and erp_ai_ro is dropped and recreated.
 */
class SetupController extends Controller
{
    public function actionDatabase(string $rootPassword, string $rootUser = 'root'): int
    {
        $local = require Yii::getAlias('@app/config/db-local.php');
        $dsn = "mysql:host={$local['host']};port={$local['port']};charset=utf8mb4";
        $root = new \PDO($dsn, $rootUser, $rootPassword, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $this->stdout("1/3 bootstrap: database + erp_app\n", Console::BOLD);
        $this->runSqlFile($root, '@app/sql/00-bootstrap.sql', ['__ERP_APP_PASSWORD__' => $local['app']['password']]);

        $this->stdout("2/3 migrations\n", Console::BOLD);
        $code = Yii::$app->runAction('migrate/up', ['interactive' => false]);
        if ($code !== ExitCode::OK) {
            return $code;
        }

        $this->stdout("3/3 restricted AI account: erp_ai_ro\n", Console::BOLD);
        $grants = $this->runSqlFile($root, '@app/sql/01-ai-readonly-user.sql', ['__ERP_AI_RO_PASSWORD__' => $local['ai']['password']]);
        foreach ($grants as $line) {
            $this->stdout("  $line\n");
        }

        $this->stdout("\nDone. Check with: php yii verify/all (after `php yii serve`)\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /** Runs a simple SQL script (statements separated by ';' at end of line). Returns the last result set's first column. */
    private function runSqlFile(\PDO $pdo, string $alias, array $replace): array
    {
        $sql = strtr(file_get_contents(Yii::getAlias($alias)), $replace);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $last = [];
        foreach (preg_split('/;\s*$/m', $sql) as $statement) {
            if (trim($statement) === '') {
                continue;
            }
            $stmt = $pdo->query($statement);
            if ($stmt->columnCount() > 0) {
                $last = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            }
        }
        return $last;
    }
}
