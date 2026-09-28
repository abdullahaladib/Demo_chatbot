<?php

declare(strict_types=1);

namespace AiChatbot;

use PDO;

/**
 * The plug-in's two database connections (PDO, both to the signed-in tenant's database):
 *
 *   app() - the tenant's own ERP account (credentials from the ERP session, exactly as the
 *           ERP itself connects). Used for identity/permissions, the audit log and the
 *           knowledge base. NEVER used to run AI-generated SQL.
 *   ai()  - the restricted read-only account from config 'aiAccounts' (SELECT on the
 *           catalogued tables/columns and v_* views only). The ONLY connection that runs
 *           AI-generated SQL. 5 s statement timeout, read-only session, real prepared statements.
 */
final class Db
{
    private static ?PDO $app = null;
    private static ?PDO $ai = null;
    private static ?array $appCredentials = null;

    /** Tenant credentials as the ERP stores them in the session at login. */
    public static function useErpSession(array $session): void
    {
        self::$appCredentials = [
            'username' => (string) ($session['db_user'] ?? ''),
            'password' => (string) ($session['db_pass'] ?? ''),
            'database' => (string) ($session['db_name'] ?? ''),
        ];
        self::$app = self::$ai = null;
    }

    /** CLI installer/tests: the admin account from config.local.php. */
    public static function useAdminAccount(): void
    {
        self::$appCredentials = Config::get('adminDb');
        self::$app = self::$ai = null;
    }

    public static function tenant(): string
    {
        return (string) (self::$appCredentials['database'] ?? '');
    }

    public static function app(): PDO
    {
        if (self::$app === null) {
            $c = self::$appCredentials ?? throw new \RuntimeException('AI chatbot: no tenant database selected');
            self::$app = self::connect($c['username'], $c['password'], $c['database']);
        }
        return self::$app;
    }

    public static function ai(): PDO
    {
        if (self::$ai === null) {
            $tenant = self::tenant();
            $account = Config::get('aiAccounts')[$tenant] ?? null;
            if (!$account) {
                throw new \RuntimeException("AI chatbot: no read-only AI account configured for tenant '$tenant'");
            }
            $pdo = self::connect($account['username'], $account['password'], $tenant);
            // Statement timeout; read-only session as belt-and-braces (the grants are the real
            // control); sql_mode WITHOUT ANSI_QUOTES / NO_BACKSLASH_ESCAPES so the server tokenises
            // strings exactly as SqlValidator does. MySQL and MariaDB name the first two differently
            // (the live ERP runs MariaDB 10.11, where MAX_EXECUTION_TIME does not exist).
            $ms = (int) Config::get('statementTimeoutMs', 5000);
            $mariaDb = stripos((string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false;
            $pdo->exec(($mariaDb
                    ? sprintf('SET SESSION max_statement_time = %.3F, SESSION tx_read_only = 1, ', $ms / 1000)
                    : sprintf('SET SESSION MAX_EXECUTION_TIME = %d, SESSION transaction_read_only = 1, ', $ms))
                . "SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
            self::$ai = $pdo;
        }
        return self::$ai;
    }

    private static function connect(string $user, string $pass, string $database): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::get('dbHost', 'localhost'), (int) Config::get('dbPort', 3306), $database);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // :me / :dept / :group bound by MySQL itself
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // The server's default utf8mb4 collation (the DSN charset alone gives the driver's
        // general_ci). Views keep the collation they were created under, so the installer and
        // every query must agree, or string comparisons fail with MySQL error 1267.
        $pdo->exec('SET NAMES utf8mb4');
        return $pdo;
    }
}
