<?php

declare(strict_types=1);

namespace app\components\ai\provider;

/**
 * Live transport: JSON over HTTPS with PHP's curl extension (no Guzzle, no SDK).
 *
 * - TLS verification is always ON; on Windows the OS certificate store is used unless a
 *   CA bundle path is configured.
 * - Throttle: every attempt waits until at least `minIntervalMs` has passed since the
 *   previous call to this provider (Mistral free tier: 1 request/second).
 * - Retry: HTTP 429 (and transient 503) is retried with exponential backoff, at most
 *   `maxAttempts` attempts in total (a Retry-After header is honoured, capped).
 *   If it still fails, the caller turns the status into a clean "service busy" message.
 */
final class HttpJson implements Transport
{
    /** @var callable(string, array, array): array{status:int, json:?array, raw:string, retryAfter?:?float} */
    private $sender;

    /**
     * @param callable|null $sender test hook replacing the real curl call
     */
    public function __construct(
        private readonly int $timeoutSeconds = 45,
        private readonly ?string $caBundle = null,
        private readonly ?Throttle $throttle = null,
        private readonly int $maxAttempts = 3,
        private readonly float $backoffBaseSeconds = 1.5,
        ?callable $sender = null,
    ) {
        $this->sender = $sender ?? $this->postOnce(...);
    }

    /** @var int number of attempts the last post() made (for tests and logs) */
    public int $lastAttempts = 0;

    public function post(string $url, array $headers, array $body): array
    {
        $response = null;
        for ($attempt = 1; $attempt <= max(1, $this->maxAttempts); $attempt++) {
            $this->throttle?->wait();
            $this->lastAttempts = $attempt;
            $response = ($this->sender)($url, $headers, $body);
            if (!in_array($response['status'], [429, 503], true) || $attempt === $this->maxAttempts) {
                break;
            }
            // Exponential backoff: 1.5s, 3s, ... or the server's Retry-After (max 10s).
            $delay = $this->backoffBaseSeconds * (2 ** ($attempt - 1));
            if (!empty($response['retryAfter'])) {
                $delay = min(10.0, max($delay, (float) $response['retryAfter']));
            }
            \Yii::warning("AI HTTP {$response['status']} on attempt $attempt; retrying in {$delay}s", __METHOD__);
            usleep((int) round($delay * 1_000_000));
        }
        return $response;
    }

    /** @return array{status:int, json:?array, raw:string, retryAfter:?float} */
    private function postOnce(string $url, array $headers, array $body): array
    {
        $retryAfter = null;
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = (float) trim(substr($line, 12));
                }
                return strlen($line);
            },
        ];
        if ($this->caBundle !== null && $this->caBundle !== '') {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        } elseif (defined('CURLSSLOPT_NATIVE_CA')) {
            $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new ProviderError(ProviderError::NETWORK, 'curl: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $json = json_decode((string) $raw, true);

        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => (string) $raw, 'retryAfter' => $retryAfter];
    }

    /** Maps a non-2xx response to a ProviderError (the raw text goes to the log, never to the user). */
    public static function fail(string $provider, array $response): never
    {
        $status = $response['status'];
        $json = $response['json'] ?? [];
        // Gemini/OpenAI: {"error":{"message":...}}; Mistral: {"message":...} or {"detail":...}
        $msg = $json['error']['message'] ?? $json['message'] ?? (is_string($json['detail'] ?? null) ? $json['detail'] : null)
            ?? substr($response['raw'], 0, 500);
        if (is_array($msg)) {
            $msg = json_encode($msg);
        }
        $category = match (true) {
            $status === 429 || $status === 503 => ProviderError::RATE_LIMITED,
            // Mistral: 403 "This model is not available in your subscription tier" - the key is fine.
            $status === 403 && preg_match('/model|tier|subscription/i', (string) $msg) => ProviderError::MODEL_UNAVAILABLE,
            $status === 401 || $status === 403 => ProviderError::AUTH,
            $status === 404 => ProviderError::MODEL_UNAVAILABLE,
            $status >= 500 => ProviderError::NETWORK,
            default => ProviderError::BAD_RESPONSE,
        };
        throw new ProviderError($category, "$provider HTTP $status: $msg", $status);
    }
}
