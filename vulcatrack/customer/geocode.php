<?php
/**
 * Landmark / address search for the Book-a-Rescue location step.
 *
 * POST-only, customer-authenticated, CSRF-checked. Returns a short JSON list of
 * { label, latitude, longitude } candidates for the customer to choose from.
 * This is only a friendlier way to pick coordinates -- the OTG request itself
 * is unchanged: rescue.php still validates the chosen lat/lng server-side and
 * freezes the ETA once, and status is still 'pending' on creation.
 *
 * The Nominatim Acceptable-Use Policy is honoured here (not inside the
 * Geocoder):
 *   - user-triggered only     -- this endpoint fires on an explicit "Search"
 *   - >= 1 request / second   -- GeocodeCache::throttle() before any outbound call
 *   - cache identical queries -- GeocodeCache::get()/put()
 *   - identifying User-Agent  -- set by NominatimGeocoder (browsers cannot)
 *   - attribution             -- rendered on the Rescue page + returned here
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Support\Geo;
use VulcaTrack\Support\GeocodeCache;
use VulcaTrack\Support\GeocoderException;
use VulcaTrack\Support\GeocoderFactory;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Emit a JSON payload and stop. Place labels are external text, so escape
 *  <, >, &, ' and " in the output as defence in depth (the page also renders
 *  them with textContent, and the response is sent nosniff as application/json). */
function geocode_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    exit;
}

$geoCfg = $GLOBALS['vulcatrack_config']['geocoding'] ?? [];
$attribution = (string) ($geoCfg['attribution'] ?? 'Search results from OpenStreetMap / Nominatim');

// --- gate: authenticated customer, POST, valid CSRF --------------------------
if (current_customer() === null) {
    geocode_respond(['ok' => false, 'error' => 'auth'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    geocode_respond(['ok' => false, 'error' => 'method'], 405);
}
if (!Csrf::check($_POST['_csrf'] ?? null)) {
    geocode_respond(['ok' => false, 'error' => 'csrf'], 400);
}

// --- query ------------------------------------------------------------------
$query = trim((string) ($_POST['q'] ?? ''));
if (mb_strlen($query) < 3) {
    geocode_respond(['ok' => false, 'error' => 'too_short']);
}
if (mb_strlen($query) > 120) {
    $query = mb_substr($query, 0, 120);
}

$limit = max(1, min((int) ($geoCfg['max_results'] ?? 5), 10));
$cache = new GeocodeCache(
    VULCATRACK_ROOT . '/storage/cache/geocode',
    (int) ($geoCfg['cache_ttl'] ?? 86400),
    (int) ($geoCfg['min_interval_ms'] ?? 1100)
);

// --- cache hit -------------------------------------------------------------
$cached = $cache->get($query);
if ($cached !== null) {
    geocode_respond(['ok' => true, 'cached' => true, 'results' => $cached, 'attribution' => $attribution]);
}

// --- provider call --------------------------------------------------------
$results = [];
try {
    // Only real (network) drivers need the rate-limit wait.
    if (!getenv('VULCATRACK_GEOCODER_FAKE')) {
        $cache->throttle();
    }
    $results = GeocoderFactory::fromConfig($GLOBALS['vulcatrack_config'])->search($query, $limit);
} catch (GeocoderException $e) {
    error_log('[geocode] ' . $e->getMessage());
    geocode_respond(['ok' => false, 'error' => 'unavailable'], 502);
}

// --- validate + shape every result (untrusted external data) --------------
$out = [];
foreach ($results as $r) {
    if (!Geo::isValidLatitude($r->latitude) || !Geo::isValidLongitude($r->longitude)) {
        continue;
    }
    $label = trim($r->label);
    if (mb_strlen($label) > 160) {
        $label = mb_substr($label, 0, 157) . '...';
    }
    $out[] = [
        'label'     => $label,
        'latitude'  => round($r->latitude, 7),
        'longitude' => round($r->longitude, 7),
    ];
}

$cache->put($query, $out);

geocode_respond([
    'ok'          => true,
    'results'     => $out,
    'attribution' => $attribution,
]);
