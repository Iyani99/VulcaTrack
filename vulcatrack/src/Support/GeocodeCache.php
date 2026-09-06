<?php

namespace VulcaTrack\Support;

/**
 * The Nominatim-policy plumbing that sits between `customer/geocode.php` and the
 * Geocoder: a small on-disk cache of recent identical queries, and an app-wide
 * minimum interval between outbound provider calls.
 *
 *  - cache  -> "Clients sending repeatedly the same query may be ... blocked";
 *              also makes the page feel instant on a repeated search.
 *  - throttle -> "an absolute maximum of 1 request per second".
 *
 * Everything is a plain file under storage/cache/geocode/ (git-ignored). Cache
 * entries store the already-normalised result array, not the raw provider body.
 */
final class GeocodeCache
{
    public function __construct(
        private string $dir,
        private int $ttlSeconds = 86400,
        private int $minIntervalMs = 1100
    ) {
    }

    /** Normalised cache key: trimmed, lower-cased, whitespace collapsed. */
    private function key(string $query): string
    {
        $norm = mb_strtolower(trim(preg_replace('/\s+/', ' ', $query) ?? ''));
        return sha1($norm);
    }

    private function path(string $query): string
    {
        return $this->dir . '/' . $this->key($query) . '.json';
    }

    private function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return true;
        }
        return @mkdir($this->dir, 0775, true) || is_dir($this->dir);
    }

    /**
     * A cached result list for this query, or null on a miss / expired entry.
     *
     * @return list<array{label:string,latitude:float,longitude:float}>|null
     */
    public function get(string $query): ?array
    {
        $file = $this->path($query);
        if (!is_file($file)) {
            return null;
        }
        if ($this->ttlSeconds > 0 && (time() - (int) @filemtime($file)) > $this->ttlSeconds) {
            @unlink($file);
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param list<array{label:string,latitude:float,longitude:float}> $results
     */
    public function put(string $query, array $results): void
    {
        if (!$this->ensureDir()) {
            return; // caching is best-effort; a write failure must not break search
        }
        @file_put_contents(
            $this->path($query),
            json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * Block until at least `minIntervalMs` has elapsed since the previous
     * outbound provider call anywhere in the app, then record "now". Uses an
     * exclusive file lock so two overlapping requests still serialise.
     */
    public function throttle(): void
    {
        if ($this->minIntervalMs <= 0 || !$this->ensureDir()) {
            return;
        }
        $lockFile = $this->dir . '/.throttle';
        $fh = @fopen($lockFile, 'c+');
        if ($fh === false) {
            return;
        }
        try {
            flock($fh, LOCK_EX);
            $last = (float) stream_get_contents($fh);
            $now = microtime(true);
            $waitMs = $this->minIntervalMs - ($now - $last) * 1000.0;
            if ($last > 0 && $waitMs > 0) {
                usleep((int) round(min($waitMs, $this->minIntervalMs) * 1000));
            }
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, (string) microtime(true));
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
