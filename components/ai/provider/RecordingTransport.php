<?php

declare(strict_types=1);

namespace app\components\ai\provider;

/**
 * Record mode: calls the live transport AND saves each successful response as a fixture,
 * so the same conversation can later be replayed with no API calls.
 */
final class RecordingTransport implements Transport
{
    private int $round = 0;

    public function __construct(
        private readonly Transport $live,
        private readonly string $conversationDir,
        private readonly array $meta,
    ) {
    }

    public function post(string $url, array $headers, array $body): array
    {
        $response = $this->live->post($url, $headers, $body);
        $this->round++;
        if ($this->round === 1 && is_dir($this->conversationDir)) {
            // Re-recording a conversation: drop the old rounds first.
            array_map('unlink', glob($this->conversationDir . '/*.json') ?: []);
        }
        if ($response['status'] >= 200 && $response['status'] < 300) {
            if (!is_dir($this->conversationDir)) {
                mkdir($this->conversationDir, 0775, true);
            }
            $doc = [
                'meta' => $this->meta + ['round' => $this->round, 'url' => $url, 'recordedAt' => date('c')],
                'request' => $body,             // body only: headers (API key) are never stored
                'response' => ['status' => $response['status'], 'json' => $response['json']],
            ];
            file_put_contents(
                sprintf('%s/%02d.json', $this->conversationDir, $this->round),
                json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }
        return $response;
    }
}
