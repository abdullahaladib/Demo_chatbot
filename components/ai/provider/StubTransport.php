<?php

declare(strict_types=1);

namespace app\components\ai\provider;

/**
 * TEST-ONLY transport: returns queued canned responses and records every request, so the
 * REAL provider clients (Mistral/Groq/Gemini wire formats) can be exercised with no network.
 */
final class StubTransport implements Transport
{
    /** @var array<int, array{url:string, headers:array, body:array}> */
    public array $requests = [];

    /** @param array<int, array> $responses JSON bodies (status 200) or full ['status'=>..,'json'=>..] */
    public function __construct(private array $responses)
    {
    }

    public function post(string $url, array $headers, array $body): array
    {
        // Round-trip through JSON exactly like the wire, so array/object issues surface.
        $this->requests[] = ['url' => $url, 'headers' => $headers,
            'body' => json_decode(json_encode($body), true), 'rawBody' => json_encode($body)];
        $next = array_shift($this->responses) ?? ['status' => 500, 'json' => ['error' => ['message' => 'stub exhausted']]];
        if (!isset($next['status'])) {
            $next = ['status' => 200, 'json' => $next];
        }
        return ['status' => $next['status'], 'json' => $next['json'], 'raw' => json_encode($next['json'])];
    }
}
