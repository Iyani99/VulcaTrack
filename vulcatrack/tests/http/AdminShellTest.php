<?php
/**
 * End-to-end HTTP tests for the Phase 5 Chunk 1 admin shell.
 *
 * Covers what only shows up across a full request: the admin guard on the
 * dashboard and the nav placeholder pages, customer/admin actor separation,
 * the admin logout staying POST + CSRF only, the shell nav containing exactly
 * Dashboard / POS / Sales History / Reports / Inventory / Tiremen / Rescue, and the rendered pages (plus the server log)
 * being free of PHP warnings/notices.
 *
 * Phase 7.3a (sidebar shell): the nav is a left sidebar in the approved order
 * Dashboard / Inventory / POS / Sales History / Rescue / Tiremen / Reports,
 * each page marks exactly its own entry aria-current="page", the sidebar shows
 * the signed-in admin and holds the POST + CSRF logout, and the customer area
 * does not pick up the admin shell.
 *
 * A throwaway customer and admin are seeded via PDO before the server starts
 * and deleted afterwards. The database must be running.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('admin shell: guards, actor separation, logout CSRF, nav and clean output', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $custEmail  = TestDb::email('shell-cust');
    $adminEmail = TestDb::email('shell-admin');
    $password   = 'shell-password-123';

    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(['Shell Customer', $custEmail, '09170000000', Password::hash($password)]);
    $custId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(['Shell Admin', $adminEmail, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();

    $adminPages = ['/vulcatrack/admin/index.php', '/vulcatrack/admin/pos.php', '/vulcatrack/admin/sales.php', '/vulcatrack/admin/reports.php', '/vulcatrack/admin/inventory.php', '/vulcatrack/admin/tiremen.php', '/vulcatrack/admin/rescue.php'];

    $assertCleanHtml = function (string $html, string $where): void {
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Parse error', 'Stack trace:', 'Undefined '] as $bad) {
            assert_not_contains($bad, $html, "PHP error text on {$where}: {$bad}");
        }
    };

    $server = new HttpServer(8680);
    $cleanup = function () use ($pdo, $custId, $adminId): void {
        $pdo->prepare('DELETE FROM customers WHERE customer_id = ?')->execute([$custId]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    };

    try {
        $server->start();

        // 1. Unauthenticated -> every admin page redirects to the admin login.
        foreach ($adminPages as $path) {
            $r = $server->request($path);
            assert_same(302, $r['status'], "{$path} must redirect an unauthenticated visitor");
            assert_contains('/admin/login.php', (string) $r['location'], "{$path} should redirect to the admin login");
        }

        // 2. A signed-in CUSTOMER cannot reach any admin page (actor separation).
        $login = $server->request('/vulcatrack/login.php');
        $token = HttpServer::csrfToken($login['body']);
        assert_not_null($token, 'the customer login form must carry a CSRF token');
        $r = $server->request('/vulcatrack/login.php', ['_csrf' => $token, 'email' => $custEmail, 'password' => $password]);
        assert_same(302, $r['status'], 'the customer login should succeed');

        foreach ($adminPages as $path) {
            $r = $server->request($path);
            assert_same(302, $r['status'], "a customer session must not satisfy the admin guard on {$path}");
            assert_contains('/admin/login.php', (string) $r['location']);
        }

        // The customer area keeps its own shell: no admin body class, sidebar,
        // admin nav or admin logout leak into customer pages (7.3a isolation).
        $custDash = $server->request('/vulcatrack/customer/dashboard.php');
        assert_same(200, $custDash['status']);
        assert_contains('<body>', $custDash['body'], 'customer pages carry no admin body class');
        assert_contains('<header class="appbar">', $custDash['body'], 'customer pages keep their top-bar shell');
        foreach (['class="admin', 'adm-side', 'adm-nav', 'Admin Terminal', '/vulcatrack/admin/logout.php'] as $adminOnly) {
            assert_not_contains($adminOnly, $custDash['body'], "customer dashboard must not contain admin shell markup: {$adminOnly}");
        }

        // Drop the customer session before switching actors.
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $token = HttpServer::csrfToken($profile['body']);
        $server->request('/vulcatrack/logout.php', ['_csrf' => $token]);

        // 3. The ADMIN can log in and open the dashboard.
        $adminLogin = $server->request('/vulcatrack/admin/login.php');
        $token = HttpServer::csrfToken($adminLogin['body']);
        assert_not_null($token, 'the admin login form must carry a CSRF token');
        $r = $server->request('/vulcatrack/admin/login.php', ['_csrf' => $token, 'email' => $adminEmail, 'password' => $password]);
        assert_same(302, $r['status'], 'the admin login should succeed');
        assert_contains('/admin/index.php', (string) $r['location']);

        $dash = $server->request('/vulcatrack/admin/index.php');
        assert_same(200, $dash['status'], 'the signed-in admin can open the dashboard');
        assert_contains('<h1>Dashboard Overview</h1>', $dash['body']);
        assert_contains('Shell Admin', $dash['body'], 'the shell shows the signed-in admin by name');
        $assertCleanHtml($dash['body'], 'admin/index.php');

        // 4. The shell nav has exactly Dashboard / POS / Sales History / Reports /
        //    Inventory / Tiremen / Rescue (Tiremen added in Phase 6 Chunk 6.1,
        //    Rescue in 6.2, Sales History and Reports after 6.3). Unapproved
        //    features stay out of the nav.
        assert_contains('>Dashboard<', $dash['body']);
        assert_contains('>POS<', $dash['body']);
        assert_contains('>Sales History<', $dash['body']);
        assert_contains('/vulcatrack/admin/sales.php"', $dash['body'], 'the Sales History nav entry links to the sales list');
        assert_contains('>Reports<', $dash['body']);
        assert_contains('/vulcatrack/admin/reports.php"', $dash['body'], 'the Reports nav entry links to the sales reports');
        assert_contains('>Inventory<', $dash['body']);
        assert_contains('>Tiremen<', $dash['body']);
        assert_contains('/vulcatrack/admin/tiremen.php"', $dash['body'], 'the Tiremen nav entry links to the Tireman list');
        assert_contains('>Rescue<', $dash['body']);
        assert_contains('/vulcatrack/admin/rescue.php"', $dash['body'], 'the Rescue nav entry links to the request list');
        foreach (['Customers', 'Analytics', 'Settings', 'Notifications', 'Feedback', 'Help'] as $absent) {
            assert_not_contains('>' . $absent . '<', $dash['body'], "the admin nav must not contain a '{$absent}' link yet");
        }

        // 4b. Phase 7.3a sidebar shell: admin body class, skip link, one nav with
        //     exactly the 7 entries in the approved order, the signed-in admin's
        //     name, and the POST + CSRF logout form inside the sidebar.
        $body = $dash['body'];
        assert_contains('<body class="admin">', $body, 'admin pages carry the admin body class');
        assert_contains('<a class="adm-skip" href="#main">Skip to content</a>', $body, 'skip link');
        assert_contains('<main class="app adm-main" id="main"', $body, 'the skip link target');
        assert_contains('<aside class="adm-side">', $body, 'the sidebar');
        assert_true(preg_match('#<nav class="adm-nav" aria-label="Admin">(.*?)</nav>#s', $body, $navMatch) === 1, 'the sidebar nav');
        preg_match_all('#<a href="([^"]+)"#', $navMatch[1], $navLinks);
        assert_same([
            '/vulcatrack/admin/index.php',
            '/vulcatrack/admin/inventory.php',
            '/vulcatrack/admin/pos.php',
            '/vulcatrack/admin/sales.php',
            '/vulcatrack/admin/rescue.php',
            '/vulcatrack/admin/tiremen.php',
            '/vulcatrack/admin/reports.php',
        ], $navLinks[1], 'exactly the 7 nav entries, in the approved order');
        assert_contains('<span class="adm-user__name">Shell Admin</span>', $body, 'the sidebar shows the signed-in admin');
        $logoutAt = strpos($body, '<form class="adm-logout" method="post" action="/vulcatrack/admin/logout.php">');
        assert_true($logoutAt !== false && $logoutAt > strpos($body, '<aside class="adm-side">') && $logoutAt < strpos($body, '</aside>'),
            'the POST logout form sits in the sidebar');
        assert_true(strpos($body, 'name="_csrf"', $logoutAt) < strpos($body, '</form>', $logoutAt), 'the logout form carries the CSRF token');

        // 4c. Every main page marks exactly its own nav entry as current.
        foreach ($adminPages as $path) {
            $page = $server->request($path);
            assert_same(200, $page['status'], "{$path} should load for the signed-in admin");
            preg_match_all('#<a href="([^"]+)"[^>]*aria-current="page"#', $page['body'], $current);
            assert_same([$path], $current[1], "{$path} marks exactly its own nav entry aria-current");
            $assertCleanHtml($page['body'], $path);
        }

        // 5. POS and Inventory are real pages (POS behaviour is covered in
        //    AdminPosTest). Both load for the admin, warning-free.
        $pos = $server->request('/vulcatrack/admin/pos.php');
        assert_same(200, $pos['status'], 'admin/pos.php should load for the signed-in admin');
        assert_contains('Point of Sale', $pos['body']);
        assert_not_contains('not available yet', $pos['body'], 'POS is no longer a placeholder');
        $assertCleanHtml($pos['body'], 'admin/pos.php');

        $inv = $server->request('/vulcatrack/admin/inventory.php');
        assert_same(200, $inv['status'], 'admin/inventory.php should load for the signed-in admin');
        assert_contains('Inventory', $inv['body']);
        $assertCleanHtml($inv['body'], 'admin/inventory.php');

        // 6. Admin logout stays POST-only + CSRF protected.
        $r = $server->request('/vulcatrack/admin/logout.php'); // GET
        assert_same(405, $r['status'], 'admin logout is POST-only');
        $r = $server->request('/vulcatrack/admin/logout.php', ['_csrf' => 'nope']);
        assert_same(400, $r['status'], 'admin logout requires a valid CSRF token');
        $probe = $server->request('/vulcatrack/admin/index.php');
        assert_same(200, $probe['status'], 'the failed logout attempts must not have ended the admin session');

        // 7. A proper admin logout ends the session.
        $token = HttpServer::csrfToken($probe['body']);
        assert_not_null($token, 'the admin shell must expose a CSRF token in its logout form');
        $r = $server->request('/vulcatrack/admin/logout.php', ['_csrf' => $token]);
        assert_same(302, $r['status']);
        assert_contains('/admin/login.php', (string) $r['location']);
        $probe = $server->request('/vulcatrack/admin/index.php');
        assert_same(302, $probe['status'], 'the admin session should be gone after logout');

        // 8. The server process logged no PHP warnings/notices/fatals.
        $stderr = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal', 'PHP Parse error'] as $bad) {
            assert_not_contains($bad, $stderr, "php -S stderr contained: {$bad}\n{$stderr}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});

test('login entry flow: separate customer + admin pages, subtle admin link, no admin registration', function () {
    $server = new HttpServer(8681);
    try {
        $server->start();

        // The public primary Login page is the CUSTOMER login: its own form posts
        // to /login.php, and there is no role selector.
        $login = $server->request('/vulcatrack/login.php');
        assert_same(200, $login['status'], '/login.php must be reachable');
        assert_contains('/vulcatrack/login.php"', $login['body'], 'the primary Login form is the customer login');
        assert_not_contains('name="role"', $login['body'], 'there must be no shared role-selection control');
        assert_not_contains('name="actor"', $login['body']);

        // A subtle path to the admin login exists from the customer login page.
        assert_contains('/vulcatrack/admin/login.php"', $login['body'], 'the customer login links to the admin login');
        assert_contains('Admin? Sign in here', $login['body']);

        // The admin login is a distinct page and offers NO registration.
        $adminLogin = $server->request('/vulcatrack/admin/login.php');
        assert_same(200, $adminLogin['status']);
        assert_contains('/vulcatrack/admin/login.php"', $adminLogin['body']);
        assert_not_contains('register', strtolower($adminLogin['body']), 'the admin login must not offer registration');
        assert_not_contains('/vulcatrack/register.php', $adminLogin['body']);

        // No admin-registration route exists.
        foreach (['/vulcatrack/admin/register.php', '/vulcatrack/admin/signup.php'] as $path) {
            $r = $server->request($path);
            assert_same(404, $r['status'], "{$path} must not exist");
        }

        $stderr = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal', 'PHP Parse error'] as $bad) {
            assert_not_contains($bad, $stderr, "php -S stderr contained: {$bad}\n{$stderr}");
        }
    } finally {
        $server->stop();
    }
});
