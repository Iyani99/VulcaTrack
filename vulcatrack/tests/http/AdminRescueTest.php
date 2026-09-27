<?php
/**
 * End-to-end HTTP tests for Phase 6 Chunk 6.2 — the READ-ONLY admin Rescue
 * pages (admin/rescue.php list, admin/rescue-view.php detail).
 *
 * Covers: the admin guard and actor separation, the status filter (default
 * pending, every valid value, junk falls back and is never echoed), strict id
 * handling (junk / unknown -> 404), escaping of every customer-controlled and
 * joined value, plain admin status labels, map assets only on the detail page,
 * no state change from any request, and the customer ownership boundary on
 * customer/booking.php.
 *
 * Throwaway customers / vehicles / requests / Tireman / admin are seeded via
 * PDO and removed afterwards. The app cannot change a request's status yet, so
 * the non-pending states are set with test-only SQL. The database must be running.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('admin rescue (read-only): guard, filters, detail, escaping, map, no mutation, ownership', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $tag = 'RSC' . substr(bin2hex(random_bytes(4)), 0, 8);
    $password = 'rsc-password-123';

    $mkCustomer = function (string $name, string $contact) use ($pdo, $password): array {
        $email = TestDb::email('rsc');
        $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
            ->execute([$name, $email, $contact, Password::hash($password)]);
        return [(int) $pdo->lastInsertId(), $email];
    };
    // Customer A carries hostile text in every field the admin page shows.
    [$custA, $emailA] = $mkCustomer("{$tag} <b>Alice</b>", '<i>0917</i>');
    [$custB, $emailB] = $mkCustomer("{$tag} Bob", '0917 222 2222');

    $adminEmail = TestDb::email('rsc-admin');
    $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
        ->execute(["{$tag} <u>Handler</u>", $adminEmail, Password::hash($password)]);
    $adminId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO vehicles (customer_id, plate_number, vehicle_type, make, model) VALUES (?,?,?,?,?)')
        ->execute([$custA, '<s>PL8</s>', 'motorcycle', '<em>Make</em>', 'Model']);
    $vehA = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO vehicles (customer_id, plate_number) VALUES (?,?)')->execute([$custB, 'RSC-B1']);
    $vehB = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO tiremen (name, contact_number) VALUES (?,?)')->execute(["{$tag} <q>Tireman</q>", '0918 333 3333']);
    $tiremanId = (int) $pdo->lastInsertId();

    $mkRequest = function (int $cust, int $veh, string $problem, string $status, ?int $tireman = null, ?int $admin = null) use ($pdo): int {
        $pdo->prepare(
            'INSERT INTO service_requests (customer_id, vehicle_id, admin_id, tireman_id, problem_description, latitude, longitude, eta_minutes, status)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$cust, $veh, $admin, $tireman, $problem, 14.9612345, 120.9054321, 23, $status]);
        return (int) $pdo->lastInsertId();
    };
    $hostileProblem = "{$tag} <script>alert('x')</script>\nsecond line";
    $reqPending   = $mkRequest($custA, $vehA, $hostileProblem, 'pending');
    $reqAccepted  = $mkRequest($custA, $vehA, "{$tag} accepted job", 'accepted', $tiremanId, $adminId);
    $reqRejected  = $mkRequest($custB, $vehB, "{$tag} rejected job", 'rejected', null, $adminId);
    $reqCompleted = $mkRequest($custB, $vehB, "{$tag} completed job", 'completed', $tiremanId, $adminId);
    $allReqs = [$reqPending, $reqAccepted, $reqRejected, $reqCompleted];

    // A snapshot of every seeded request row, to prove nothing ever changes.
    $snapshot = function () use ($pdo, $allReqs): array {
        $in = implode(',', array_map('intval', $allReqs));
        return $pdo->query("SELECT * FROM service_requests WHERE request_id IN ({$in}) ORDER BY request_id")->fetchAll(\PDO::FETCH_ASSOC);
    };
    $before = $snapshot();

    $assertCleanHtml = function (string $html, string $where): void {
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Parse error', 'Stack trace:', 'Undefined '] as $bad) {
            assert_not_contains($bad, $html, "PHP error text on {$where}: {$bad}");
        }
    };

    $server = new HttpServer(8688);
    $cleanup = function () use ($pdo, $allReqs, $custA, $custB, $adminId, $tiremanId, $vehA, $vehB): void {
        $in = implode(',', array_map('intval', $allReqs));
        $pdo->exec("DELETE FROM service_requests WHERE request_id IN ({$in})");
        $pdo->prepare('DELETE FROM vehicles WHERE vehicle_id IN (?, ?)')->execute([$vehA, $vehB]);
        $pdo->prepare('DELETE FROM tiremen WHERE tireman_id = ?')->execute([$tiremanId]);
        $pdo->prepare('DELETE FROM customers WHERE customer_id IN (?, ?)')->execute([$custA, $custB]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id = ?')->execute([$adminId]);
    };

    $LIST = '/vulcatrack/admin/rescue.php';
    $VIEW = '/vulcatrack/admin/rescue-view.php';

    try {
        $server->start();

        // ============ SECURITY: unauthenticated ============
        foreach ([$LIST, $LIST . '?status=all', $VIEW . '?id=' . $reqPending] as $path) {
            $r = $server->request($path);
            assert_same(302, $r['status'], "{$path} redirects an unauthenticated visitor");
            assert_contains('/admin/login.php', (string) $r['location']);
        }

        // ============ SECURITY: customer session — no admin access; ownership intact ============
        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']), 'email' => $emailB, 'password' => $password]);
        foreach ([$LIST, $VIEW . '?id=' . $reqRejected] as $path) {
            $r = $server->request($path);
            assert_same(302, $r['status'], "a customer session must not open {$path}");
            assert_contains('/admin/login.php', (string) $r['location']);
        }
        $r = $server->request('/vulcatrack/customer/booking.php?id=' . $reqRejected);
        assert_same(200, $r['status'], 'customer B can open their own request');
        $r = $server->request('/vulcatrack/customer/booking.php?id=' . $reqPending);
        assert_same(404, $r['status'], "customer B cannot open customer A's request by changing the id");
        assert_not_contains($tag . ' &lt;script', $r['body'], "none of A's request leaks to B");
        $mine = $server->request('/vulcatrack/customer/bookings.php');
        assert_not_contains('booking.php?id=' . $reqPending . '"', $mine['body'], "B's history does not list A's request");
        assert_contains('booking.php?id=' . $reqRejected . '"', $mine['body']);
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $server->request('/vulcatrack/logout.php', ['_csrf' => HttpServer::csrfToken($profile['body'])]);

        // ============ log in as admin ============
        $al = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($al['body']), 'email' => $adminEmail, 'password' => $password]);

        // ============ LIST: default = pending ============
        $list = $server->request($LIST);
        assert_same(200, $list['status'], 'the admin can view the request list');
        assert_contains('>Rescue<', $list['body'], 'the Rescue nav entry is present');
        assert_contains('<option value="pending" selected>', $list['body'], 'pending is the default filter');
        assert_contains('rescue-view.php?id=' . $reqPending . '"', $list['body']);
        foreach ([$reqAccepted, $reqRejected, $reqCompleted] as $id) {
            assert_not_contains('rescue-view.php?id=' . $id . '"', $list['body'], 'the default list shows pending only');
        }
        assert_contains('badge--pending">Pending<', $list['body'], 'plain admin label + badge');
        assert_not_contains('Pending review', $list['body'], 'no customer wording on admin screens');
        assert_not_contains('<b>Alice</b>', $list['body'], 'the customer name is escaped in the list');
        assert_contains('&lt;b&gt;Alice&lt;/b&gt;', $list['body']);
        assert_not_contains('<s>PL8</s>', $list['body'], 'the plate is escaped in the list');
        assert_same(1, substr_count($list['body'], 'method="post"'), 'the list has no form except logout');
        assert_not_contains('leaflet.js', $list['body'], 'no map assets on the list');
        $assertCleanHtml($list['body'], 'rescue.php');

        // ============ LIST: every valid filter ============
        $expect = ['pending' => $reqPending, 'accepted' => $reqAccepted, 'rejected' => $reqRejected, 'completed' => $reqCompleted];
        foreach ($expect as $status => $id) {
            $r = $server->request($LIST . '?status=' . $status);
            assert_same(200, $r['status']);
            assert_contains('<option value="' . $status . '" selected>', $r['body'], "{$status} is selected");
            assert_contains('rescue-view.php?id=' . $id . '"', $r['body'], "{$status} shows its request");
            foreach (array_diff($allReqs, [$id]) as $other) {
                assert_not_contains('rescue-view.php?id=' . $other . '"', $r['body'], "{$status} hides other statuses");
            }
            assert_contains('>' . ucfirst($status) . '</span>', $r['body'], "{$status} renders its plain label");
        }
        $r = $server->request($LIST . '?status=accepted');
        assert_contains('&lt;q&gt;Tireman&lt;/q&gt;', $r['body'], 'the Tireman name is shown, escaped');
        assert_not_contains('<q>Tireman</q>', $r['body']);
        assert_not_contains('Tireman is on the way', $r['body'], 'no customer wording on admin screens');

        $r = $server->request($LIST . '?status=all');
        assert_contains('<option value="all" selected>', $r['body']);
        foreach ($allReqs as $id) {
            assert_contains('rescue-view.php?id=' . $id . '"', $r['body'], 'all shows every status');
        }

        // ============ LIST: junk filter falls back to pending, never echoed ============
        foreach (['"><script>alert(1)</script>', 'cancelled', 'ALL', "pending' OR '1'='1"] as $junk) {
            $r = $server->request($LIST . '?status=' . rawurlencode($junk));
            assert_same(200, $r['status'], "junk filter '{$junk}' is handled safely");
            assert_contains('<option value="pending" selected>', $r['body'], "junk filter '{$junk}' falls back to pending");
            assert_not_contains('<script>alert(1)</script>', $r['body']);
            assert_not_contains('cancelled', $r['body']);
            assert_not_contains('rescue-view.php?id=' . $reqAccepted . '"', $r['body']);
        }

        // ============ DETAIL: pending, unassigned, hostile text ============
        $d = $server->request($VIEW . '?id=' . $reqPending);
        assert_same(200, $d['status'], 'the admin can open a request');
        assert_contains('Request #' . $reqPending, $d['body']);
        assert_contains('badge--pending">Pending<', $d['body']);
        assert_not_contains('Pending review', $d['body']);
        assert_not_contains("<script>alert('x')</script>", $d['body'], 'the problem description is escaped');
        assert_contains('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;<br />', $d['body'], 'escaped first, then line breaks kept');
        assert_contains('second line', $d['body']);
        assert_contains('&lt;b&gt;Alice&lt;/b&gt;', $d['body']);
        assert_not_contains('<b>Alice</b>', $d['body'], 'the customer name is escaped');
        assert_contains('&lt;i&gt;0917&lt;/i&gt;', $d['body'], 'the customer contact is escaped');
        assert_not_contains('<i>0917</i>', $d['body']);
        assert_contains('href="tel:0917"', $d['body'], 'the tel: link keeps only digits');
        assert_contains(e($emailA), $d['body'], 'the customer email is shown');
        assert_contains('&lt;s&gt;PL8&lt;/s&gt;', $d['body'], 'the plate is escaped');
        assert_not_contains('<s>PL8</s>', $d['body']);
        assert_contains('&lt;em&gt;Make&lt;/em&gt; Model motorcycle', $d['body'], 'vehicle details are escaped');
        assert_contains('No Tireman assigned.', $d['body']);
        assert_contains('Not yet handled by an admin.', $d['body']);
        assert_contains('23 minutes', $d['body'], 'the stored ETA is shown');
        assert_contains('14.96123, 120.90543', $d['body'], 'the stored coordinates are shown');
        $assertCleanHtml($d['body'], 'rescue-view.php (pending)');

        // Map: assets + read-only map element on the detail page only.
        assert_contains('/assets/lib/leaflet/leaflet.js', $d['body'], 'Leaflet is loaded on the detail page');
        assert_contains('/assets/lib/leaflet/leaflet.css', $d['body']);
        assert_contains('/assets/js/otg-map.js', $d['body']);
        assert_contains('id="otg-map"', $d['body']);
        assert_contains('data-readonly="1"', $d['body'], 'the admin map is read-only');
        assert_contains('data-cust-lat="14.9612345"', $d['body'], 'the map uses the stored coordinates');
        assert_contains('data-cust-label="Customer location"', $d['body']);
        assert_same(1, substr_count($d['body'], 'method="post"'), 'the detail page has no form except logout');
        foreach (['name="status"', 'name="tireman_id"', 'name="_action"'] as $control) {
            assert_not_contains($control, $d['body'], "no {$control} control on the read-only page");
        }

        // ============ DETAIL: accepted, assigned + handled ============
        $d = $server->request($VIEW . '?id=' . $reqAccepted);
        assert_same(200, $d['status']);
        assert_contains('badge--accepted">Accepted<', $d['body']);
        assert_not_contains('Tireman is on the way', $d['body']);
        assert_contains('&lt;q&gt;Tireman&lt;/q&gt;', $d['body'], 'the Tireman name is escaped');
        assert_not_contains('<q>Tireman</q>', $d['body']);
        assert_contains('href="tel:09183333333"', $d['body'], 'the Tireman contact is shown');
        assert_contains('&lt;u&gt;Handler&lt;/u&gt;', $d['body'], 'the handling admin name is escaped');
        assert_not_contains('<u>Handler</u>', $d['body']);

        foreach ([$reqRejected => 'Rejected', $reqCompleted => 'Completed'] as $id => $label) {
            $d = $server->request($VIEW . '?id=' . $id);
            assert_same(200, $d['status']);
            assert_contains('">' . $label . '</span>', $d['body'], "{$label} renders its plain label");
            $assertCleanHtml($d['body'], "rescue-view.php ({$label})");
        }

        // ============ DETAIL: junk / unknown ids -> 404 ============
        foreach (['abc', '0', '-1', '1.5', '1e3', '2147483648', '', '2147483647'] as $badId) {
            $r = $server->request($VIEW . '?id=' . rawurlencode($badId));
            assert_same(404, $r['status'], "id '{$badId}' is not found");
            assert_contains('Request not found', $r['body']);
            assert_not_contains('leaflet.js', $r['body'], 'no map assets on the not-found page');
            $assertCleanHtml($r['body'], "rescue-view.php?id={$badId}");
        }
        $r = $server->request($VIEW);
        assert_same(404, $r['status'], 'a missing id is not found');

        // ============ Map assets are not loaded on unrelated admin pages ============
        foreach (['/vulcatrack/admin/index.php', '/vulcatrack/admin/tiremen.php', '/vulcatrack/admin/inventory.php'] as $path) {
            assert_not_contains('leaflet.js', $server->request($path)['body'], "no Leaflet on {$path}");
        }

        // ============ No state change from GET or POST ============
        $server->request($VIEW . '?id=' . $reqPending . '&status=accepted&tireman_id=' . $tiremanId . '&_action=accept');
        $server->request($LIST . '?status=pending&_action=accept&request_id=' . $reqPending);
        $csrf = HttpServer::csrfToken($server->request($LIST)['body']);
        $server->request($VIEW . '?id=' . $reqPending, ['_csrf' => (string) $csrf, '_action' => 'accept', 'status' => 'accepted', 'tireman_id' => (string) $tiremanId]);
        $server->request($LIST, ['_csrf' => (string) $csrf, '_action' => 'reject', 'request_id' => (string) $reqPending]);
        assert_same($before, $snapshot(), 'no request row changed (status, tireman, admin, updated_at, ETA)');

        // ============ server log is clean ============
        $stderr = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal', 'PHP Parse error'] as $bad) {
            assert_not_contains($bad, $stderr, "php -S stderr contained: {$bad}\n{$stderr}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});
