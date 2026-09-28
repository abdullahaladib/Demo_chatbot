<?php

declare(strict_types=1);

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * One-command database setup for a fresh machine (needs trainingclouderp_training_db.sql in the
 * project root - it is gitignored, copy it over separately).
 *
 *   php yii setup/database <mysql-root-password>
 *
 * 1. runs sql/00-bootstrap.sql as root (erp_app account), with the password from config/db-local.php
 * 2. setup/import-training: (re)creates erp_training from the dump (~6 minutes)
 * 3. runs `yii migrate` (ai_* tables, demo people + passwords, views, knowledge base)
 * 4. runs sql/01-ai-readonly-user.sql as root (erp_ai_ro, SELECT on the v_* views only)
 *
 * Safe to re-run: it rebuilds erp_training from the dump, and erp_ai_ro is dropped and recreated.
 */
class SetupController extends Controller
{
    /**
     * Import the real training ERP dump (MariaDB) into MySQL 8, untouched except for the
     * MariaDB-only syntax rewritten by TrainingDumpConverter.
     *
     *   php yii setup/import-training <mysql-root-password> [dumpPath] [database]
     *
     * DROPS and recreates the target database, grants erp_app on it, streams the dump in
     * through the mysql client, and fails loudly on any error. Takes ~6-7 minutes.
     */
    public function actionImportTraining(
        string $rootPassword,
        string $dump = '@app/trainingclouderp_training_db.sql',
        string $database = 'erp_training',
        string $rootUser = 'root',
    ): int {
        $local = require Yii::getAlias('@app/config/db-local.php');
        $dump = Yii::getAlias($dump);
        if (!is_file($dump)) {
            $this->stderr("Dump not found: $dump\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            $this->stderr("Invalid database name\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->stdout("1/4 converting MariaDB dump for MySQL 8\n", Console::BOLD);
        $converted = Yii::getAlias('@runtime/' . $database . '.mysql8.sql');
        $converter = new \app\components\TrainingDumpConverter();
        $converter->convert($dump, $converted);
        $this->stdout('    ' . json_encode($converter->stats) . "\n");

        $this->stdout("2/4 recreating database `$database`, granting erp_app\n", Console::BOLD);
        $root = new \PDO("mysql:host={$local['host']};port={$local['port']};charset=utf8mb4", $rootUser, $rootPassword,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $root->exec("DROP DATABASE IF EXISTS `$database`");
        $root->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        foreach (['localhost', '127.0.0.1'] as $host) {
            $root->exec("GRANT ALL PRIVILEGES ON `$database`.* TO 'erp_app'@'$host'");
        }

        $this->stdout("3/4 importing (several minutes)\n", Console::BOLD);
        $started = time();
        $cmd = sprintf('%s --user=%s --host=%s --port=%d --default-character-set=utf8mb4 %s',
            escapeshellarg($this->mysqlBinary()), escapeshellarg($rootUser),
            escapeshellarg($local['host']), (int) $local['port'], escapeshellarg($database));
        $proc = proc_open($cmd, [0 => ['file', $converted, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            null, array_merge(getenv(), ['MYSQL_PWD' => $rootPassword]));
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            $this->stderr("Import FAILED (exit $code):\n" . trim($out . "\n" . $err) . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        @unlink($converted);

        $count = (int) $root->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $root->quote($database))->fetchColumn();
        $this->stdout(sprintf("4/4 done in %ds: %d tables in `%s`\n", time() - $started, $count, $database), Console::FG_GREEN);
        return ExitCode::OK;
    }

    /** The mysql client: config db-local 'mysqlBinary', else the default Windows install path, else PATH. */
    private function mysqlBinary(): string
    {
        $local = require Yii::getAlias('@app/config/db-local.php');
        foreach ([$local['mysqlBinary'] ?? null, 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysql.exe'] as $candidate) {
            if ($candidate && is_file($candidate)) {
                return $candidate;
            }
        }
        return 'mysql';
    }

    public function actionDatabase(string $rootPassword, string $rootUser = 'root'): int
    {
        $local = require Yii::getAlias('@app/config/db-local.php');
        $dsn = "mysql:host={$local['host']};port={$local['port']};charset=utf8mb4";
        $root = new \PDO($dsn, $rootUser, $rootPassword, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $this->stdout("1/4 bootstrap: erp_app account\n", Console::BOLD);
        $this->runSqlFile($root, '@app/sql/00-bootstrap.sql', ['__ERP_APP_PASSWORD__' => $local['app']['password']]);

        $this->stdout("2/4 training ERP import\n", Console::BOLD);
        $code = $this->actionImportTraining($rootPassword, '@app/trainingclouderp_training_db.sql', $local['dbname'], $rootUser);
        if ($code !== ExitCode::OK) {
            return $code;
        }
        Yii::$app->db->close(); // the database was recreated underneath the connection

        $this->stdout("3/4 migrations\n", Console::BOLD);
        $code = Yii::$app->runAction('migrate/up', ['interactive' => false]);
        if ($code !== ExitCode::OK) {
            return $code;
        }

        $this->stdout("4/4 restricted AI account: erp_ai_ro\n", Console::BOLD);
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
