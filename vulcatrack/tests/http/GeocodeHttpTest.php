<?php
/**
 * End-to-end HTTP tests for customer/geocode.php (the landmark-search endpoint).
 *
 * Runs `php -S` with VULCATRACK_GEOCODER_FAKE=1 so the endpoint uses the
 * in-memory ArrayGeocoder fixtures -- no network, deterministic. Seeds a
 * throwaway customer, deletes it after.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('geocode endpoint: auth gate, CSRF, JSON shape, and clean output', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable');

    $email = TestDb::email('geo');
    $password = 'geo-password-123';
    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(['Geo Customer', $email, '09170000000', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO vehicles (customer_id, plate_number) VALUES (?, ?)')->execute([$custId, 'GEO-1']);
    $vehId = (int) $pdo->lastInsertId();

    $server = new HttpServer(8678, ['VULCATRACK_GEOCODER_FAKE' => '1']);
    $cleanup = function () use ($pdo, $custId, $vehId): void {
        $pdo->prepare('DELETE FROM vehicles WHERE vehicle_id = ?')->execute([$vehId]);
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
    };

    try {
        $server->start();

        // 1. Unauthenticated -> 401 JSON, not a redirect.
        $r = $server->request('/vulcatrack/customer/geocode.php', ['q' => 'Shell']);
        assert_same(401, $r['status'], 'an unauthenticated caller must be rejected');
        assert_contains('application/json', $r['headers']);
        $j = json_decode($r['body'], true);
        assert_same(false, $j['ok'] ?? null);
        assert_same('auth', $j['error'] ?? null);

        // Log in.
        $login = $server->request('/vulcatrack/login.php');
        $token = HttpServer::csrfToken($login['body']);
        $server->request('/vulcatrack/login.php', ['_csrf' => $token, 'email' => $email, 'password' => $password]);

        // Need a CSRF token from a signed-in page (the rescue form carries one).
        $rescue = $server->request('/vulcatrack/customer/rescue.php');
        $csrf = HttpServer::csrfToken($rescue['body']);
        assert_not_null($csrf, 'the rescue form must carry a CSRF token for the search fetch');
        assert_contains('id="otg-search-q"', $rescue['body'], 'the rescue page shows the landmark search box');
        assert_contains('OpenStreetMap', $rescue['body'], 'attribution is rendered on the page');

        // 2. GET is refused.
        $r = $server->request('/vulcatrack/customer/geocode.php');
        assert_same(405, $r['status']);

        // 3. Authenticated POST without CSRF -> 400.
        $r = $server->request('/vulcatrack/customer/geocode.php', ['q' => 'Shell']);
        assert_same(400, $r['status']);
        assert_same('csrf', (json_decode($r['body'], true)['error'] ?? null));

        // 4. Too-short query.
        $r = $server->request('/vulcatrack/customer/geocode.php', ['_csrf' => $csrf, 'q' => 'ab']);
        assert_same('too_short', (json_decode($r['body'], true)['error'] ?? null));

        // 5. A real search (fake geocoder) -> ok:true with valid coordinates.
        $r = $server->request('/vulcatrack/customer/geocode.php', ['_csrf' => $csrf, 'q' => 'Shell Baliwag']);
        assert_same(200, $r['status']);
        assert_contains('application/json', $r['headers']);
        assert_contains('nosniff', $r['headers']);
        $j = json_decode($r['body'], true);
        assert_same(true, $j['ok'] ?? null);
        assert_true(is_array($j['results']) && count($j['results']) >= 1, 'expected at least one match');
        $first = $j['results'][0];
        assert_contains('Shell', $first['label']);
        assert_true(is_numeric($first['latitude']) && $first['latitude'] > 14 && $first['latitude'] < 15);
        assert_true(is_numeric($first['longitude']) && $first['longitude'] > 120 && $first['longitude'] < 121);
        assert_contains('OpenStreetMap', $j['attribution']);

        // 6. No-result search -> ok:true, empty list (handled gracefully).
        $r = $server->request('/vulcatrack/customer/geocode.php', ['_csrf' => $csrf, 'q' => 'zzz nowhere qqq']);
        $j = json_decode($r['body'], true);
        assert_same(true, $j['ok'] ?? null);
        assert_same([], $j['results']);

        // 7. Identical query is served from cache the second time.
        $r = $server->request('/vulcatrack/customer/geocode.php', ['_csrf' => $csrf, 'q' => 'Shell Baliwag']);
        $j = json_decode($r['body'], true);
        assert_same(true, $j['cached'] ?? null, 'a repeated identical query should hit the cache');

        // 8. The signed-in customer still cannot reach an admin page (unchanged).
        $r = $server->request('/vulcatrack/admin/index.php');
        assert_same(302, $r['status']);

        // 9. Server log clean.
        $log = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal', 'PHP Parse error'] as $bad) {
            assert_not_contains($bad, $log, "php -S stderr contained {$bad}\n{$log}");
        }
    } finally {
        $server->stop();
        $cleanup();
        // wipe cache entries this test created
        foreach (glob(VULCATRACK_APP_ROOT . '/storage/cache/geocode/*') ?: [] as $f) {
            @unlink($f);
        }
    }
});

test('geocode endpoint escapes hostile place labels in the JSON body', function () {
    $pdo = test_pdo();
    $email = TestDb::email('geo-xss');
    $password = 'geo-password-123';
    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(['Geo XSS', $email, '09170000000', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();

    // Extra fixture with an HTML-ish label, injected via the offline driver.
    $server = new HttpServer(8679, [
        'VULCATRACK_GEOCODER_FAKE' => '1',
    ]);

    try {
        $server->start();
        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', [
            '_csrf' => HttpServer::csrfToken($login['body']), 'email' => $email, 'password' => $password,
        ]);
        // The appbar logout form carries a CSRF token on every signed-in page.
        $csrf = HttpServer::csrfToken($server->request('/vulcatrack/customer/dashboard.php')['body']);

        // The fake fixtures include a deliberately hostile label ("Tondo
        // <script>alert(1)</script> & \"quotes\""). The endpoint must emit it
        // escaped -- no raw tag, ampersand or quote in the JSON string.
        $r = $server->request('/vulcatrack/customer/geocode.php', ['_csrf' => $csrf, 'q' => 'Tondo']);
        assert_same(200, $r['status']);
        $j = json_decode($r['body'], true);
        assert_true(is_array($j['results']) && count($j['results']) === 1, 'hostile fixture matched');
        assert_contains('script', $j['results'][0]['label'], 'the label text itself is preserved once decoded');
        assert_not_contains('<script', $r['body']);
        assert_not_contains('<', $r['body'], 'JSON_HEX_TAG: no raw < in the response body');
        assert_not_contains('</', $r['body']);
    } finally {
        $server->stop();
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
        foreach (glob(VULCATRACK_APP_ROOT . '/storage/cache/geocode/*') ?: [] as $f) {
            @unlink($f);
        }
    }
});
