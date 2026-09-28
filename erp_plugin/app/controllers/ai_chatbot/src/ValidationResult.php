<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * Outcome of SqlValidator::validate().
 *
 * On success, $sql is the rewritten query (LIMIT enforced, scoped tables wrapped) with named
 * placeholders, for display; $executableSql is the same with positional `?` markers, and
 * $placeholders lists which session value ('me' / 'dept' / 'group') goes in each position.
 * The VALUES are never known to the validator - QueryExecutor binds them from the session.
 *
 * A rejection is either a SECURITY REFUSAL (the turn ends with a plain-English refusal) or,
 * when $retryable, a correctable MISTAKE by the model (a typo'd table, SELECT *, a missing
 * scope filter, a bad LIMIT...) which is sent back to the model as a hint - still never run.
 */
final class ValidationResult
{
    /** Model mistakes worth a retry - they are not attempts to reach forbidden data. */
    public const RETRYABLE = ['select_star', 'unknown_table', 'missing_scope', 'bad_limit', 'parse',
        'parenthesised_table', 'qualified_table', 'bad_placeholder', 'unterminated_string',
        'unterminated_identifier', 'too_long', 'empty'];

    private function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly string $reason,
        public readonly string $userMessage,
        public readonly string $sql = '',
        public readonly string $executableSql = '',
        public readonly array $placeholders = [],
        public readonly array $tables = [],
        public readonly array $notes = [],
    ) {
    }

    public static function pass(string $sql, string $executableSql, array $placeholders, array $tables, array $notes): self
    {
        return new self(true, 'ok', '', '', $sql, $executableSql, $placeholders, $tables, $notes);
    }

    public static function reject(string $code, string $reason, string $userMessage): self
    {
        return new self(false, $code, $reason, $userMessage);
    }

    public function retryable(): bool
    {
        return !$this->ok && in_array($this->code, self::RETRYABLE, true);
    }
}
