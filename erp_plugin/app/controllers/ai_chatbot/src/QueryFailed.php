<?php

declare(strict_types=1);

namespace AiChatbot;

/**
 * A validated query that MySQL refused or could not run. Carries a SAFE category only;
 * the raw MySQL message goes to the plug-in log, never to the user or the model.
 */
final class QueryFailed extends \RuntimeException
{
    private const USER_MESSAGES = [
        'permission_denied' => "You're not authorised to access that data.",
        'timeout' => 'That lookup took too long and was stopped. Try a narrower question.',
        'bad_query' => "I couldn't build a working query for that question. Try rephrasing it.",
        'scope_unavailable' => "Your ERP account isn't linked to an employee record, so I can't look that up for you.",
        'database_error' => 'Something went wrong looking that up. Please try again.',
    ];

    public function __construct(
        public readonly string $category,
        string $detail = '',
        public readonly int $mysqlCode = 0,
    ) {
        parent::__construct($detail !== '' ? $detail : $category);
    }

    public function userMessage(): string
    {
        return self::USER_MESSAGES[$this->category] ?? self::USER_MESSAGES['database_error'];
    }
}
