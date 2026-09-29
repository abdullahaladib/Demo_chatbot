<?php

declare(strict_types=1);

namespace AiChatbot\provider;

/**
 * Minimal JSON-over-HTTPS POST using PHP's curl extension (no Guzzle, no SDK).
 * TLS verification is always ON; on Windows the OS certificate store is used unless a
 * CA bundle path is configured.
 */
final class HttpJson
{
    /** Wall-clock end of the current chat turn (unix time); 0 = none. Set by ChatService. */
    private static float $deadline = 0.0;

    public static function setDeadline(float $unixTime): void
    {
        self::$deadline = $unixTime;
    }

    private static function remaining(): float
    {
        return self::$deadline > 0 ? self::$deadline - microtime(true) : INF;
    }

    public function __construct(
        private readonly int $timeoutSeconds = 45,
        private readonly ?string $caBundle = null,
    ) {
    }

    /**
     * @param string[] $headers
     * @return array{status:int, json:?array, raw:string}
     */
    public function post(string $url, array $headers, array $body): array
    {
        // Free tiers return transient 503 ("high demand") and 429 (per-minute quota).
        // Retry twice with a short backoff before giving up.
        // Never retry past the turn's time budget: the request would be killed mid-wait.
        foreach ([0, 2, 5] as $attempt => $waitSeconds) {
            if ($attempt > 0 && self::remaining() < $waitSeconds + 10) {
                break;
            }
            sleep($waitSeconds);
            $response = $this->postOnce($url, $headers, $body);
            if (!in_array($response['status'], [429, 503], true)) {
                break;
            }
            \AiChatbot\Log::warning("AI HTTP {$response['status']} on attempt " . ($attempt + 1));
        }
        return $response;
    }

    /**
     * One GET, no retries (used to list the models an API key can use: a metadata call, not a question).
     * @param string[] $headers
     * @return array{status:int, json:?array, raw:string}
     */
    public function get(string $url, array $headers): array
    {
        return $this->postOnce($url, $headers, null);
    }

    /** @return array{status:int, json:?array, raw:string} */
    private function postOnce(string $url, array $headers, ?array $body): array
    {
        $ch = curl_init($url);
        $opts = ($body === null ? [CURLOPT_HTTPGET => true] : [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]) + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => (int) max(5, min($this->timeoutSeconds, floor(self::remaining()))),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($this->caBundle !== null && $this->caBundle !== '') {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        } elseif (defined('CURLSSLOPT_NATIVE_CA')) {
            $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            if (curl_errno($ch) === CURLE_OPERATION_TIMEDOUT) {
                throw new ProviderError(ProviderError::TIMEOUT, 'curl: ' . curl_error($ch));
            }
            throw new ProviderError(ProviderError::NETWORK, 'curl: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $json = json_decode((string) $raw, true);

        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => (string) $raw];
    }

    /** Maps a non-2xx response to a ProviderError. */
    public static function fail(string $provider, array $response): never
    {
        $status = $response['status'];
        $msg = $response['json']['error']['message'] ?? substr($response['raw'], 0, 500);
        $category = match (true) {
            $status === 429 => ProviderError::RATE_LIMITED,
            $status === 401 || $status === 403 => ProviderError::AUTH,
            $status === 404 => ProviderError::MODEL_UNAVAILABLE,
            $status === 503 => ProviderError::BUSY,
            $status >= 500 => ProviderError::NETWORK,
            default => ProviderError::BAD_RESPONSE,
        };
        throw new ProviderError($category, "$provider HTTP $status: $msg", $status);
    }
}
