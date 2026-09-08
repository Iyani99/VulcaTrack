<?php
/**
 * Regression: VulcaTrack must be reachable from another device on the LAN
 * (a phone hitting the PC's LAN IP) and from a future deployment hostname,
 * not only from "localhost".
 *
 * The bug: vulcatrack_url() built absolute URLs from the fixed
 * app.base_url ("http://localhost/vulcatrack"), so every form action and
 * redirect pointed at "localhost". On a phone, "localhost" is the phone
 * itself -> Safari "cannot connect" the moment the login form is submitted.
 *
 * The fix: generated URLs are host-relative ("/vulcatrack/login.php"), so the
 * browser stays on whatever origin the visitor actually used.
 *
 * This test drives a real server with a Host header that is NOT localhost and
 * asserts no generated URL leaks a scheme/host.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('generated URLs are host-relative so the app works over a LAN IP / other host', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $email = TestDb::email('lanhost');
    $password = 'lanhost-password-123';
    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(['LAN Host Customer', $email, '09170000000', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();

    // A stand-in for "some other host" — a LAN IP or a deployment hostname.
    // NOT hardcoded anywhere in the app; the app must not care what it is.
    $otherHost = ['Host: vulcatrack.lan'];

    $noSchemeHost = function (string $url, string $where): void {
        assert_not_contains('://', $url, "{$where} leaked a scheme");
        assert_not_contains('localhost', $url, "{$where} leaked 'localhost'");
        assert_not_contains('127.0.0.1', $url, "{$where} leaked '127.0.0.1'");
        assert_not_contains('vulcatrack.lan', $url, "{$where} pinned a specific host");
        assert_same('/', substr($url, 0, 1), "{$where} is not rooted at '/'");
    };

    $server = new HttpServer(8684);
    $cleanup = function () use ($pdo, $custId): void {
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
    };

    try {
        $server->start();

        // 1. The login form's action, as a non-localhost client sees it.
        $login = $server->request('/vulcatrack/login.php', null, false, $otherHost);
        assert_same(200, $login['status']);
        assert_same(1, preg_match('~<form method="post" action="([^"]*)"~', $login['body'], $m),
            'the login form has an action');
        $noSchemeHost($m[1], 'login form action');
        assert_same('/vulcatrack/login.php', $m[1]);

        // Every link/action/src on the page is host-relative too.
        preg_match_all('~(?:href|src|action)="([^"]+)"~', $login['body'], $all);
        foreach ($all[1] as $u) {
            if (strpos($u, 'vulcatrack') !== false) {
                $noSchemeHost($u, "asset/link {$u}");
            }
        }

        // 2. An auth-guard redirect (unauthenticated -> login) is host-relative.
        $g = $server->request('/vulcatrack/customer/dashboard.php', null, false, $otherHost);
        assert_same(302, $g['status']);
        $noSchemeHost((string) $g['location'], 'auth-guard Location');
        assert_contains('/login.php', (string) $g['location']);

        // 3. The successful-login redirect, as the same client sees it.
        $token = HttpServer::csrfToken($login['body']);
        $r = $server->request('/vulcatrack/login.php', [
            '_csrf' => $token, 'email' => $email, 'password' => $password,
        ], false, $otherHost);
        assert_same(302, $r['status'], 'a valid login redirects');
        $noSchemeHost((string) $r['location'], 'post-login Location');
        assert_same('/vulcatrack/customer/dashboard.php', $r['location']);

        // 4. The redirect target actually loads for that same client.
        $dash = $server->request('/vulcatrack/customer/dashboard.php', null, false, $otherHost);
        assert_same(200, $dash['status'], 'the dashboard loads over the non-localhost host');
        assert_contains('LAN Host Customer', $dash['body']);

        // 5. No PHP warnings/notices in the server log.
        $stderr = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal', 'PHP Parse error'] as $bad) {
            assert_not_contains($bad, $stderr, "php -S stderr contained: {$bad}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});
