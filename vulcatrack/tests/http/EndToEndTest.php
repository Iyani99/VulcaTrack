<?php
/**
 * End-to-end HTTP tests -- drive a real `php -S` process the way a browser
 * would (cookie-aware), covering the Phase 3/4 behaviour that only shows up
 * across a full request: auth guards, actor separation, CSRF enforcement,
 * page reachability, and the absence of PHP warnings/notices in the output.
 *
 * A throwaway customer and admin are seeded directly via PDO before the server
 * starts and deleted afterwards (FK-safe order). The database must be running.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('end-to-end: guards, actor separation, CSRF and clean output', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    // --- seed a throwaway customer + admin (committed; cleaned up below) ------
    $custEmail = TestDb::email('e2e-cust');
    $adminEmail = TestDb::email('e2e-admin');
    $password = 'e2e-password-123';

    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(['E2E Customer', $custEmail, '09170000000', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(['E2E Admin', $adminEmail, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();

    $server = new HttpServer(8677);
    $cleanup = function () use ($pdo, $custId, $adminId): void {
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    };

    try {
        $server->start();

        // 1. Unauthenticated customer page -> redirect to the customer login.
        $r = $server->request('/vulcatrack/customer/dashboard.php');
        assert_same(302, $r['status'], 'an unauthenticated customer page must redirect');
        assert_contains('/login.php', (string) $r['location']);

        // 2. Unauthenticated admin page -> redirect to the admin login.
        $r = $server->request('/vulcatrack/admin/index.php');
        assert_same(302, $r['status']);
        assert_contains('/admin/login.php', (string) $r['location']);

        // 3. Public pages are reachable and warning-free.
        foreach (['/vulcatrack/', '/vulcatrack/login.php', '/vulcatrack/register.php', '/vulcatrack/admin/login.php', '/vulcatrack/health.php'] as $path) {
            $r = $server->request($path);
            assert_same(200, $r['status'], "{$path} should be 200");
            assert_no_php_errors($r['body'], $path);
        }

        // 4. health.php reports the environment as healthy.
        $r = $server->request('/vulcatrack/health.php');
        assert_contains('All checks passed', $r['body']);

        // 5. Login with a bad CSRF token -> not authenticated.
        $login = $server->request('/vulcatrack/login.php');
        $token = HttpServer::csrfToken($login['body']);
        assert_not_null($token, 'the login form must carry a CSRF token');

        $r = $server->request('/vulcatrack/login.php', [
            '_csrf'    => 'bogus-token',
            'email'    => $custEmail,
            'password' => $password,
        ]);
        assert_same(200, $r['status'], 'a bad-CSRF login re-renders the form, not a redirect');
        assert_contains('session expired', strtolower($r['body']));
        $probe = $server->request('/vulcatrack/customer/dashboard.php');
        assert_same(302, $probe['status'], 'the bad-CSRF login must not have created a session');

        // 6. Login with the real CSRF token -> authenticated, dashboard reachable.
        $login = $server->request('/vulcatrack/login.php');
        $token = HttpServer::csrfToken($login['body']);
        $r = $server->request('/vulcatrack/login.php', [
            '_csrf'    => $token,
            'email'    => $custEmail,
            'password' => $password,
        ]);
        assert_same(302, $r['status'], 'a valid login redirects');
        assert_contains('/customer/dashboard.php', (string) $r['location']);

        $dash = $server->request('/vulcatrack/customer/dashboard.php');
        assert_same(200, $dash['status'], 'the authenticated customer can open the dashboard');
        assert_contains('E2E Customer', $dash['body']);
        assert_no_php_errors($dash['body'], 'customer/dashboard.php');

        // 7. Every Phase 4 customer page loads for the signed-in customer, warning-free.
        foreach ([
            '/vulcatrack/customer/profile.php',
            '/vulcatrack/customer/vehicles.php',
            '/vulcatrack/customer/vehicle-edit.php',
            '/vulcatrack/customer/rescue.php',
            '/vulcatrack/customer/bookings.php',
        ] as $path) {
            $r = $server->request($path);
            assert_same(200, $r['status'], "{$path} should load for the customer");
            assert_no_php_errors($r['body'], $path);
        }

        // 8. The signed-in CUSTOMER still cannot reach an ADMIN page (actor separation).
        $r = $server->request('/vulcatrack/admin/index.php');
        assert_same(302, $r['status'], 'a customer session must not satisfy the admin guard');
        assert_contains('/admin/login.php', (string) $r['location']);

        // 9. Logout without CSRF is rejected (POST-only + CSRF).
        $r = $server->request('/vulcatrack/logout.php'); // GET
        assert_same(405, $r['status'], 'logout is POST-only');
        $r = $server->request('/vulcatrack/logout.php', ['_csrf' => 'nope']);
        assert_same(400, $r['status'], 'logout requires a valid CSRF token');
        $probe = $server->request('/vulcatrack/customer/dashboard.php');
        assert_same(200, $probe['status'], 'the failed logout attempts must not have ended the session');

        // 10. A proper logout ends the session.
        $dash = $server->request('/vulcatrack/customer/dashboard.php');
        // dashboard has no form; grab a token from the profile page instead.
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $token = HttpServer::csrfToken($profile['body']);
        $r = $server->request('/vulcatrack/logout.php', ['_csrf' => $token]);
        assert_same(302, $r['status']);
        $probe = $server->request('/vulcatrack/customer/dashboard.php');
        assert_same(302, $probe['status'], 'the session should be gone after logout');

        // 11. Admin can log in on the admin side and reach the admin area.
        $adminLogin = $server->request('/vulcatrack/admin/login.php');
        $token = HttpServer::csrfToken($adminLogin['body']);
        $r = $server->request('/vulcatrack/admin/login.php', [
            '_csrf'    => $token,
            'email'    => $adminEmail,
            'password' => $password,
        ]);
        assert_same(302, $r['status']);
        assert_contains('/admin/index.php', (string) $r['location']);
        $area = $server->request('/vulcatrack/admin/index.php');
        assert_same(200, $area['status']);
        assert_contains('E2E Admin', $area['body']);
        assert_no_php_errors($area['body'], 'admin/index.php');

        // 12. The signed-in ADMIN cannot reach a CUSTOMER page.
        $r = $server->request('/vulcatrack/customer/dashboard.php');
        assert_same(302, $r['status'], 'an admin session must not satisfy the customer guard');

        // 13. The server process logged no PHP warnings/notices/fatals.
        $stderr = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal', 'PHP Parse error'] as $bad) {
            assert_not_contains($bad, $stderr, "php -S stderr contained: {$bad}\n{$stderr}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});

/** Fail if a rendered page leaked a PHP error string. */
function assert_no_php_errors(string $html, string $where): void
{
    foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Parse error', 'Stack trace:', 'Undefined ' ] as $bad) {
        assert_not_contains($bad, $html, "PHP error text on {$where}: {$bad}");
    }
}
