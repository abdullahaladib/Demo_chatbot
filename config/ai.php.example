<?php

/**
 * AI provider settings (committed). API KEYS DO NOT GO HERE: put them in the gitignored
 * config/ai-local.php (copy config/ai-local.php.example), which is merged over this file.
 * Keys can also come from the environment: MISTRAL_API_KEY / GEMINI_API_KEY / GROQ_API_KEY.
 *
 * Switching provider on demo day is ONE line: change 'provider'.
 *
 *   Mistral key (free mode): https://console.mistral.ai/api-keys
 *   Gemini key  (free):      https://aistudio.google.com/apikey
 *   Groq key    (free):      https://console.groq.com/keys
 */
return [
    'provider' => 'mistral',         // 'mistral' (primary) | 'gemini' | 'groq'

    // 'live'   - call the provider API
    // 'record' - call the API AND save each response under fixturesDir
    // 'replay' - NO API calls: answer from the recorded fixtures (no key needed).
    //            The tools still run for real: validator, :me/:dept binding, dbAi, audit.
    'mode' => 'live',
    'fixturesDir' => '@app/tests/fixtures/ai',

    // Response cache: the same user asking the same question again (with the same data and
    // model) is answered from cache with no AI call. Clear it: php yii ai-cache/clear
    'cache' => [
        'enabled' => true,           // false = always call the AI
        'ttlSeconds' => 604800,      // 7 days
    ],

    // 0 = deterministic SQL generation. Same question, same SQL, on the projector.
    'temperature' => 0,
    'timeoutSeconds' => 45,
    'maxToolRounds' => 6,            // model <-> tool round-trips per question

    // HTTP 429/503 are retried with exponential backoff (1.5s, 3s), at most 3 attempts.
    'retry' => [
        'maxAttempts' => 3,
        'backoffBaseSeconds' => 1.5,
    ],

    'providers' => [
        'mistral' => [
            'type' => 'openai-compatible',
            'apiKey' => '',          // set in config/ai-local.php (gitignored), not here
            // FREE MODE (checked 2026-09-28 via the x-ratelimit-* response headers): mistral-large,
            // -medium, -small and magistral are NOT included (0 requests/minute). Available:
            //   ministral-14b-latest  30 req/min   <- default: most capable general model on free
            //   codestral-latest     125 req/min   (code-specialised; good SQL)
            //   ministral-8b-latest  188 req/min
            // On a paid tier, switch to 'mistral-large-latest' and lower minIntervalMs.
            'model' => 'ministral-14b-latest',
            'baseUrl' => 'https://api.mistral.ai/v1',
            // Throttle: one chat turn makes 2-3 calls back to back. Every call waits until at least
            // minIntervalMs after the previous one, across all requests. Keep it >= 60000 / req-per-minute
            // (+ margin): 2100 for ministral-14b (30/min); 1100 is enough for a 1 req/second limit.
            'minIntervalMs' => 2100,
            'toolMessageName' => true, // Mistral's tool-result message carries the function name
        ],
        'gemini' => [
            'type' => 'gemini',
            'apiKey' => '',          // set in config/ai-local.php (gitignored), not here
            // gemini-2.5-* is closed to new keys ("no longer available to new users").
            'model' => 'gemini-3.6-flash',
            'baseUrl' => 'https://generativelanguage.googleapis.com/v1beta',
            // Optional, model-specific. 2.5: ['thinkingBudget' => 0]  3.x: ['thinkingLevel' => 'low']
            'thinkingConfig' => null,
            'minIntervalMs' => 0,
        ],
        'groq' => [
            'type' => 'openai-compatible',
            'apiKey' => '',          // set in config/ai-local.php (gitignored), not here
            'model' => 'openai/gpt-oss-120b',
            'baseUrl' => 'https://api.groq.com/openai/v1',
            'minIntervalMs' => 0,
            'toolMessageName' => false,
        ],
    ],

    // PHP on Windows often ships without a CA bundle. By default the OS certificate
    // store is used (TLS verification stays ON). Set a path to a cacert.pem to override.
    'caBundle' => null,
];
