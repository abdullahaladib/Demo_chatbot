<?php

declare(strict_types=1);

namespace app\components\ai\provider;

/**
 * A provider/API failure. $category is safe to act on; the message (which may contain
 * the provider's raw error) goes to the application log only.
 */
final class ProviderError extends \RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const RATE_LIMITED = 'rate_limited';
    public const AUTH = 'auth';
    public const MODEL_UNAVAILABLE = 'model_unavailable';
    public const NETWORK = 'network';
    public const BAD_RESPONSE = 'bad_response';
    public const FIXTURE_MISSING = 'fixture_missing';

    public function __construct(
        public readonly string $category,
        string $message,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return match ($this->category) {
            self::NOT_CONFIGURED => 'The AI assistant is not configured yet: add an API key in config/ai-local.php.',
            self::RATE_LIMITED => 'The AI service is busy right now. Please try again in a moment.',
            self::AUTH => 'The AI service rejected our API key. Check the key in config/ai-local.php.',
            self::MODEL_UNAVAILABLE => 'The configured AI model is not available for this API key. Switch the model in config/ai.php.',
            self::FIXTURE_MISSING => 'Replay mode is on and there is no recorded answer for this question. Record it first, or set \'mode\' => \'live\' in config/ai.php.',
            default => 'The AI service is unavailable right now. Please try again shortly.',
        };
    }
}
