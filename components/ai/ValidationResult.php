<?php

declare(strict_types=1);

namespace app\components\ai;

/**
 * Outcome of SqlValidator::validate().
 *
 * On success, $sql is the rewritten query (LIMIT enforced, scoped views wrapped) with
 * named placeholders, for display; $executableSql is the same with positional `?`
 * markers, and $placeholders lists which session value ('me' / 'dept') goes in each.
 * The VALUES are never known to the validator - QueryExecutor binds them from the session.
 */
final class ValidationResult
{
    /**
     * @param string[] $placeholders e.g. ['me', 'dept', 'me'] in positional order
     * @param string[] $views        allowlisted views the query references
     * @param string[] $notes        human-readable rewrites that were applied
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly string $reason,
        public readonly string $userMessage,
        public readonly string $sql = '',
        public readonly string $executableSql = '',
        public readonly array $placeholders = [],
        public readonly array $views = [],
        public readonly array $notes = [],
    ) {
    }

    public static function pass(string $sql, string $executableSql, array $placeholders, array $views, array $notes): self
    {
        return new self(true, 'ok', '', '', $sql, $executableSql, $placeholders, $views, $notes);
    }

    /**
     * @param string $code        machine code, e.g. 'view_not_allowed'
     * @param string $reason      technical reason for the audit log (never shown to the user)
     * @param string $userMessage plain-English refusal shown to the user
     */
    public static function reject(string $code, string $reason, string $userMessage): self
    {
        return new self(false, $code, $reason, $userMessage);
    }
}
