<?php
/**
 * End-to-end HTTP test for the public landing page (index.php, Phase 7.2).
 *
 * Covers: public 200, the approved content (identity, the three features incl.
 * "Rescue Status & ETA", How It Works, footer), the entry links (customer login,
 * register, Book a Rescue → the guarded booking page, Admin Portal, section
 * anchors) built from the app URL helper, the signed-in variants of the header
 * action, and — importantly — that no stale developer content (later phases,
 * health.php, local paths) or live-tracking claim appears in the visible page.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('landing page: public, accurate content, entry links, no stale or live-tracking copy', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $password  = 'landing-password-123';
    $custEmail = TestDb::email('lp-cust');
    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(['Landing Customer', $custEmail, '09170004444', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();

    $server = new HttpServer(8694);
    try {
        $server->start();

        $r = $server->request('/vulcatrack/');
        assert_same(200, $r['status'], 'the landing page is public');
        $html = $r['body'];
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Undefined ', 'SQLSTATE'] as $bad) {
            assert_not_contains($bad, $html, "PHP error text on the landing page: {$bad}");
        }

        // approved content
        foreach (['VulcaTrack', 'Sales and Inventory', 'with On-the-Go Services', 'Gerald Tabayag Vulcanizing Shop',
                  'On-the-Go Rescue', 'Sales &amp; Inventory', 'Rescue Status &amp; ETA', 'How It Works',
                  'Book', 'Status', 'Service', 'Done', 'Quick Links', '504 San Jose St. Baliwag, Bulacan'] as $text) {
            assert_contains($text, $html, "landing page shows: {$text}");
        }

        // entry links (app URL helper → /vulcatrack/...), anchors
        foreach (['href="/vulcatrack/login.php"', 'href="/vulcatrack/register.php"', 'href="/vulcatrack/customer/rescue.php"',
                  'href="/vulcatrack/admin/login.php"', 'href="#features"', 'href="#how-it-works"',
                  'id="features"', 'id="how-it-works"'] as $link) {
            assert_contains($link, $html, "landing page has {$link}");
        }
        assert_not_contains('localhost', $html, 'no hard-coded host');
        assert_contains('>Login</a>', $html, 'a guest sees Login');

        // Book a Rescue is the guarded booking page: a guest ends at the customer login.
        $r = $server->request('/vulcatrack/customer/rescue.php');
        assert_same(302, $r['status']);
        assert_contains('/login.php', (string) $r['location']);

        // no stale developer content, no live-tracking claim in the visible page
        foreach (['health.php', 'C:\\', 'IPT102', 'project-decisions'] as $bad) {
            assert_not_contains($bad, $html, "no developer detail: {$bad}");
        }
        $visible = strtolower(html_entity_decode(strip_tags(preg_replace('#<(script|style|title)\b.*?</\1>#si', '', $html))));
        foreach (['later phase', 'real-time', 'realtime', 'live tracking', 'live location', 'lalamove', 'gps', 'track your technician'] as $claim) {
            assert_not_contains($claim, $visible, "no stale or unsupported claim: {$claim}");
        }

        // signed-in customer: header offers the customer dashboard instead of Login
        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']), 'email' => $custEmail, 'password' => $password]);
        $html = $server->request('/vulcatrack/')['body'];
        assert_contains('href="/vulcatrack/customer/dashboard.php">My Dashboard</a>', $html);
        assert_not_contains('>Login</a>', $html);

        $log = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal'] as $bad) {
            assert_not_contains($bad, $log, "server log contains: {$bad}");
        }
    } finally {
        $server->stop();
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
    }
});
