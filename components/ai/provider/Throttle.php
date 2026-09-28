<?php

declare(strict_types=1);

namespace app\components\ai\provider;

/**
 * Keeps consecutive requests to one provider at least $minIntervalMs apart - across
 * ALL PHP requests, not just within one chat turn (a turn makes 2-3 calls, and the
 * next question may arrive a moment later from another request).
 *
 * The last-call time lives in a small file; an exclusive flock() serialises callers,
 * so two browser tabs cannot both fire at once.
 */
final class Throttle
{
    public function __construct(
        private readonly string $stateFile,
        private readonly int $minIntervalMs,
    ) {
    }

    /** Blocks until the interval has passed, then records "now". Returns the ms waited. */
    public function wait(): int
    {
        if ($this->minIntervalMs <= 0) {
            return 0;
        }
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = fopen($this->stateFile, 'c+');
        if ($fh === false) {
            return 0; // cannot throttle; the 429 retry still protects us
        }
        try {
            flock($fh, LOCK_EX);
            $last = (float) stream_get_contents($fh);
            $waitUs = (int) round(($last + $this->minIntervalMs / 1000 - microtime(true)) * 1_000_000);
            if ($waitUs > 0) {
                usleep($waitUs);
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, sprintf('%.6F', microtime(true)));
            fflush($fh);
            return max(0, intdiv($waitUs, 1000));
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
