<?php

declare(strict_types=1);

namespace app\components\ai\provider;

/**
 * How a provider client sends one JSON request. Three implementations:
 *   HttpJson            - live HTTPS (curl) with throttle + 429 retry
 *   RecordingTransport  - live, and also saves each response as a fixture
 *   ReplayTransport     - no network at all: returns previously recorded fixtures
 * The provider clients (Gemini, OpenAI-compatible) never know which one they have.
 */
interface Transport
{
    /**
     * @param string[] $headers
     * @return array{status:int, json:?array, raw:string}
     */
    public function post(string $url, array $headers, array $body): array;
}
