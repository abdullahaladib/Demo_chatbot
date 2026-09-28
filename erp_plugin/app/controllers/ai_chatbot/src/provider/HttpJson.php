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
        foreach ([0, 2, 5] as $attempt => $waitSeconds) {
            sleep($waitSeconds);
            $response = $this->postOnce($url, $headers, $body);
            if (!in_array($response['status'], [429, 503], true)) {
                break;
            }
            \AiChatbot\Log::warning("AI HTTP {$response['status']} on attempt " . ($attempt + 1));
        }
        return $response;
    }

    /** @return array{status:int, json:?array, raw:string} */
    private function postOnce(string $url, array $headers, array $body): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
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
            $status >= 500 => ProviderError::NETWORK,
            default => ProviderError::BAD_RESPONSE,
        };
        throw new ProviderError($category, "$provider HTTP $status: $msg", $status);
    }
}
