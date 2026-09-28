<?php

declare(strict_types=1);

namespace app\components\ai\provider;

/**
 * Replay mode: NO network. Returns the recorded response for each successive API call
 * of this conversation. The tools still run for real (validator, :me/:dept binding,
 * dbAi, audit) - only the model's replies are canned.
 */
final class ReplayTransport implements Transport
{
    private int $round = 0;

    public function __construct(private readonly string $conversationDir)
    {
    }

    public function post(string $url, array $headers, array $body): array
    {
        $this->round++;
        $file = sprintf('%s/%02d.json', $this->conversationDir, $this->round);
        if (!is_file($file)) {
            throw new ProviderError(ProviderError::FIXTURE_MISSING,
                "No recorded response #{$this->round} in " . basename($this->conversationDir));
        }
        $doc = json_decode((string) file_get_contents($file), true);
        return [
            'status' => (int) ($doc['response']['status'] ?? 200),
            'json' => $doc['response']['json'] ?? null,
            'raw' => '',
        ];
    }
}
