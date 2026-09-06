<?php
/**
 * Unit tests for GeocodeCache -- the Nominatim-policy plumbing (identical-query
 * cache + >= 1 req/sec throttle) that wraps the geocoder in customer/geocode.php.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Support\GeocodeCache;

function tmp_cache_dir(): string
{
    $dir = sys_get_temp_dir() . '/vulcatrack_geocache_' . bin2hex(random_bytes(5));
    mkdir($dir, 0775, true);
    register_shutdown_function(static function () use ($dir) {
        foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
        foreach (glob($dir . '/.*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
        @rmdir($dir);
    });
    return $dir;
}

test('GeocodeCache round-trips a result list', function () {
    $c = new GeocodeCache(tmp_cache_dir(), 3600, 0);
    $results = [['label' => 'Shell Baliwag', 'latitude' => 14.95, 'longitude' => 120.90]];
    assert_null($c->get('shell baliwag'), 'miss before put');
    $c->put('shell baliwag', $results);
    assert_same($results, $c->get('shell baliwag'));
});

test('GeocodeCache normalises the key: whitespace + case do not matter', function () {
    $c = new GeocodeCache(tmp_cache_dir(), 3600, 0);
    $c->put('  Shell   Baliwag ', [['label' => 'x', 'latitude' => 1, 'longitude' => 2]]);
    assert_not_null($c->get('shell baliwag'), 'differently-spaced identical query should hit');
    assert_not_null($c->get('SHELL BALIWAG'));
});

test('GeocodeCache expires an entry past its TTL', function () {
    $dir = tmp_cache_dir();
    $c = new GeocodeCache($dir, 1, 0);
    $c->put('q', [['label' => 'x', 'latitude' => 1, 'longitude' => 2]]);
    // Backdate the file mtime past the TTL.
    $files = glob($dir . '/*.json');
    touch($files[0], time() - 10);
    assert_null($c->get('q'), 'expired entry should be a miss');
    assert_false(is_file($files[0]), 'expired entry should be removed');
});

test('GeocodeCache::throttle waits out the minimum interval between calls', function () {
    $c = new GeocodeCache(tmp_cache_dir(), 3600, 250); // 250 ms floor
    $c->throttle(); // first call: records "now", no wait
    $start = microtime(true);
    $c->throttle(); // second call: should block ~250 ms
    $elapsedMs = (microtime(true) - $start) * 1000;
    assert_true($elapsedMs >= 200, "expected a ~250ms wait, got {$elapsedMs}ms");
    assert_true($elapsedMs < 1500, "wait should be bounded, got {$elapsedMs}ms");
});

test('GeocodeCache::throttle with a zero interval never blocks', function () {
    $c = new GeocodeCache(tmp_cache_dir(), 3600, 0);
    $start = microtime(true);
    $c->throttle(); $c->throttle();
    assert_true((microtime(true) - $start) < 0.1);
});

test('GeocodeCache write failure is swallowed (search must not break on a bad dir)', function () {
    // A file where the cache directory should be -> mkdir/​fopen must fail,
    // and neither put() nor get() may throw.
    $blocker = sys_get_temp_dir() . '/vulcatrack_geocache_blocker_' . bin2hex(random_bytes(4));
    file_put_contents($blocker, 'x');
    register_shutdown_function(static fn () => @unlink($blocker));

    $c = new GeocodeCache($blocker . '/cache', 3600, 50);
    $c->put('q', [['label' => 'x', 'latitude' => 1, 'longitude' => 2]]);
    assert_null($c->get('q'), 'a cache that cannot write returns misses, not errors');
    $c->throttle(); // also must not throw
});
