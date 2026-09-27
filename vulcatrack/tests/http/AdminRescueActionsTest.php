<?php
/**
 * End-to-end HTTP tests for Phase 6 Chunk 6.3 — admin Rescue status actions on
 * admin/rescue-view.php (accept + assign, reassign, reject, complete) and the
 * customer's view of the result on customer/booking.php.
 *
 * Covers: the admin guard / actor separation on POST, CSRF, GET never
 * mutating, unknown actions, strict ids, active-Tireman validation, the
 * approved transitions only (rejected / completed are final), stale-state
 * refusals that never overwrite newer data, admin_id = the acting admin,
 * Tireman history kept on final requests, the right controls per state,
 * one-time flashes, and the customer booking page (on-the-way wording only
 * while accepted; completed keeps the Tireman's name; ownership intact).
 *
 * Throwaway rows are seeded via PDO and removed afterwards. The database must
 * be running.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('admin rescue actions: accept/reassign/reject/complete rules, stale guards, customer view', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $tag = 'RSA' . substr(bin2hex(random_bytes(4)), 0, 8);
    $password = 'rsa-password-123';

    $mkCustomer = function (string $name) use ($pdo, $password): array {
        $email = TestDb::email('rsa');
        $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
            ->execute([$name, $email, '0917 000 0000', Password::hash($password)]);
        return [(int) $pdo->lastInsertId(), $email];
    };
    [$custA, $emailA] = $mkCustomer("{$tag} Customer A");
    [$custB] = $mkCustomer("{$tag} Customer B");

    $mkAdmin = function (string $name) use ($pdo, $password): array {
        $email = TestDb::email('rsa-admin');
        $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
            ->execute([$name, $email, Password::hash($password)]);
        return [(int) $pdo->lastInsertId(), $email];
    };
    [$admin1, $adminEmail] = $mkAdmin("{$tag} Admin One");
    [$admin2] = $mkAdmin("{$tag} Admin Two");

    $vehicles = [];
    foreach ([$custA, $custB] as $c) {
        $pdo->prepare('INSERT INTO vehicles (customer_id, plate_number) VALUES (?,?)')->execute([$c, 'RSA-' . $c]);
        $vehicles[$c] = (int) $pdo->lastInsertId();
    }

    $mkTireman = function (string $name, int $active) use ($pdo): int {
        $pdo->prepare('INSERT INTO tiremen (name, contact_number, is_active) VALUES (?,?,?)')->execute([$name, '0918 000 0000', $active]);
        return (int) $pdo->lastInsertId();
    };
    $tA   = $mkTireman("{$tag} Tireman Alpha", 1);
    $tB   = $mkTireman("{$tag} Tireman Bravo", 1);
    $tOff = $mkTireman("{$tag} Tireman Offline", 0);

    $reqIds = [];
    $mkRequest = function (int $cust, string $status = 'pending', ?int $tireman = null, ?int $admin = null) use ($pdo, $vehicles, &$reqIds): int {
        $pdo->prepare(
            "INSERT INTO service_requests
                 (customer_id, vehicle_id, admin_id, tireman_id, problem_description, latitude, longitude, eta_minutes, status, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?, '2020-01-01 00:00:00')"
        )->execute([$cust, $vehicles[$cust], $admin, $tireman, 'RSA problem', 14.95, 120.89, 12, $status]);
        return $reqIds[] = (int) $pdo->lastInsertId();
    };
    $r1 = $mkRequest($custA);                             // accept -> reassign -> complete
    $r2 = $mkRequest($custA);                             // pending -> rejected
    $r3 = $mkRequest($custA);                             // accept -> rejected (Tireman kept)
    $r4 = $mkRequest($custA);                             // stale-state checks
    $r5 = $mkRequest($custA, 'accepted', $tA, $admin2);   // reassign changes admin_id; stays accepted
    $r6 = $mkRequest($custA, 'accepted', null, $admin2);  // legacy: accepted with no Tireman
    $rB = $mkRequest($custB);                             // another customer's request

    $row = fn (int $id) => $pdo->query('SELECT * FROM service_requests WHERE request_id = ' . $id)->fetch(\PDO::FETCH_ASSOC);

    $assertCleanHtml = function (string $html, string $where): void {
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Parse error', 'Stack trace:', 'Undefined '] as $bad) {
            assert_not_contains($bad, $html, "PHP error text on {$where}: {$bad}");
        }
    };

    $server = new HttpServer(8689);
    $cleanup = function () use ($pdo, &$reqIds, $vehicles, $tag, $custA, $custB, $admin1, $admin2): void {
        $pdo->exec('DELETE FROM service_requests WHERE request_id IN (' . implode(',', array_map('intval', $reqIds)) . ')');
        foreach ($vehicles as $vid) {
            $pdo->prepare('DELETE FROM vehicles WHERE vehicle_id = ?')->execute([$vid]);
        }
        $pdo->prepare('DELETE FROM tiremen WHERE name LIKE ?')->execute([$tag . '%']);
        $pdo->prepare('DELETE FROM customers WHERE customer_id IN (?, ?)')->execute([$custA, $custB]);
        $pdo->prepare('DELETE FROM admins WHERE admin_id IN (?, ?)')->execute([$admin1, $admin2]);
    };

    $VIEW = '/vulcatrack/admin/rescue-view.php';
    $LIST = '/vulcatrack/admin/rescue.php';

    try {
        $server->start();

        // ============ SECURITY: unauthenticated / customer sessions cannot act ============
        $snap = $row($r1);
        $r = $server->request($VIEW . '?id=' . $r1, ['_action' => 'accept', 'tireman_id' => (string) $tA, '_csrf' => 'x']);
        assert_same(302, $r['status']);
        assert_contains('/admin/login.php', (string) $r['location'], 'an unauthenticated POST is refused');

        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']), 'email' => $emailA, 'password' => $password]);
        $profile = $server->request('/vulcatrack/customer/profile.php');
        $r = $server->request($VIEW . '?id=' . $r1, ['_action' => 'accept', 'tireman_id' => (string) $tA, '_csrf' => (string) HttpServer::csrfToken($profile['body'])]);
        assert_contains('/admin/login.php', (string) $r['location'], 'a customer session cannot act, even with its own CSRF token');
        assert_same($snap, $row($r1), 'nothing changed');
        $server->request('/vulcatrack/logout.php', ['_csrf' => HttpServer::csrfToken($profile['body'])]);

        // ============ log in as admin 1 ============
        $al = $server->request('/vulcatrack/admin/login.php');
        $server->request('/vulcatrack/admin/login.php', ['_csrf' => HttpServer::csrfToken($al['body']), 'email' => $adminEmail, 'password' => $password]);
        $tok = fn () => (string) HttpServer::csrfToken($server->request($LIST)['body']);
        $post = fn (int $id, array $fields) => $server->request($VIEW . '?id=' . $id, ['_csrf' => $tok()] + $fields);
        $view = fn (int $id) => $server->request($VIEW . '?id=' . $id)['body'];

        // ============ GET never mutates; bad CSRF / unknown action / bad ids refused ============
        $server->request($VIEW . '?id=' . $r1 . '&_action=accept&tireman_id=' . $tA);
        assert_same($snap, $row($r1), 'a GET with action parameters does nothing');

        $r = $server->request($VIEW . '?id=' . $r1, ['_csrf' => 'bogus', '_action' => 'accept', 'tireman_id' => (string) $tA]);
        assert_same(302, $r['status']);
        assert_contains('rescue-view.php?id=' . $r1, (string) $r['location']);
        assert_same($snap, $row($r1), 'a bad-CSRF accept did nothing');
        assert_contains('session expired', strtolower($view($r1)), 'the refusal is flashed');
        assert_not_contains('session expired', strtolower($view($r1)), 'the flash is one-time');

        foreach (['delete', 'reopen', 'pending', ''] as $bad) {
            $post($r1, ['_action' => $bad, 'tireman_id' => (string) $tA]);
            assert_same($snap, $row($r1), "action '{$bad}' does nothing");
            assert_contains('could not be completed', $view($r1));
        }
        foreach (['abc', '0', '-1', '2147483648', '2147483647'] as $badId) {
            $r = $server->request($VIEW . '?id=' . rawurlencode($badId), ['_csrf' => $tok(), '_action' => 'accept', 'tireman_id' => (string) $tA]);
            assert_same(404, $r['status'], "a POST to request id '{$badId}' is not found");
        }
        assert_same($snap, $row($r1));

        // ============ PENDING: controls ============
        $d = $view($r1);
        assert_contains('name="_action" value="accept"', $d);
        assert_contains('name="_action" value="reject"', $d);
        assert_contains('name="expected_status" value="pending"', $d);
        assert_not_contains('value="complete"', $d, 'a pending request cannot be completed');
        assert_not_contains('value="reassign"', $d);
        assert_contains('<option value="' . $tA . '">', $d, 'active Tiremen are offered');
        assert_contains('<option value="' . $tB . '">', $d);
        assert_not_contains("{$tag} Tireman Offline", $d, 'an inactive Tireman is never offered');
        assert_not_contains('Read-only view', $d);
        $assertCleanHtml($d, 'rescue-view.php (pending)');

        // ============ ACCEPT: a valid active Tireman is required ============
        foreach (['missing' => null, 'blank' => '', 'malformed' => 'abc', 'zero' => '0', 'unknown' => '2147483647', 'inactive' => (string) $tOff] as $label => $tid) {
            $fields = ['_action' => 'accept'];
            if ($tid !== null) { $fields['tireman_id'] = $tid; }
            $post($r1, $fields);
            assert_same($snap, $row($r1), "accept with a {$label} Tireman does nothing");
            assert_contains('Choose an active Tireman', $view($r1), "{$label}: the reason is flashed");
        }

        $r = $post($r1, ['_action' => 'accept', 'tireman_id' => (string) $tA, 'admin_id' => (string) $admin2]);
        assert_same(302, $r['status']);
        assert_contains('rescue-view.php?id=' . $r1, (string) $r['location'], 'redirects back to the request');
        $a = $row($r1);
        assert_same('accepted', $a['status']);
        assert_same($tA, (int) $a['tireman_id'], 'acceptance assigns the Tireman in the same action');
        assert_same($admin1, (int) $a['admin_id'], 'admin_id is the signed-in admin, never a posted value');
        assert_true($a['updated_at'] !== '2020-01-01 00:00:00');
        $d = $view($r1);
        assert_contains("Request accepted. {$tag} Tireman Alpha is assigned.", $d);
        assert_not_contains('Request accepted.', $view($r1), 'the success flash is one-time');

        // ============ ACCEPTED: controls ============
        $d = $view($r1);
        assert_contains('badge--accepted">Accepted<', $d);
        assert_not_contains('Tireman is on the way', $d, 'admin screens use plain labels');
        assert_contains('name="_action" value="reassign"', $d);
        assert_contains('name="_action" value="complete"', $d);
        assert_contains('name="_action" value="reject"', $d);
        assert_contains('name="expected_status" value="accepted"', $d);
        assert_contains('name="expected_tireman_id" value="' . $tA . '"', $d);
        assert_not_contains('value="accept"', $d, 'an accepted request cannot be accepted again');
        assert_contains('<option value="' . $tB . '">', $d, 'another active Tireman is offered');
        assert_not_contains('<option value="' . $tA . '">', $d, 'the current Tireman is not offered again');
        assert_not_contains("{$tag} Tireman Offline", $d);

        // ============ REASSIGN: refusals ============
        $snap = $row($r1);
        $post($r1, ['_action' => 'reassign', 'expected_tireman_id' => (string) $tA, 'tireman_id' => (string) $tA]);
        assert_contains('already assigned', $view($r1));
        $post($r1, ['_action' => 'reassign', 'expected_tireman_id' => (string) $tA, 'tireman_id' => (string) $tOff]);
        assert_contains('Choose an active Tireman', $view($r1));
        $post($r1, ['_action' => 'reassign', 'expected_tireman_id' => (string) $tA, 'tireman_id' => '2147483647']);
        assert_contains('Choose an active Tireman', $view($r1));
        // Stale: the admin was looking at Tireman B (someone changed it since).
        $post($r1, ['_action' => 'reassign', 'expected_tireman_id' => (string) $tB, 'tireman_id' => (string) $tA]);
        assert_contains('This request changed', $view($r1));
        $post($r1, ['_action' => 'reassign', 'expected_tireman_id' => 'abc', 'tireman_id' => (string) $tB]);
        assert_contains('This request changed', $view($r1));
        assert_same($snap, $row($r1), 'no refused reassignment changed anything');

        // ============ REASSIGN: success ============
        $post($r1, ['_action' => 'reassign', 'expected_tireman_id' => (string) $tA, 'tireman_id' => (string) $tB]);
        $a = $row($r1);
        assert_same('accepted', $a['status'], 'reassignment keeps the request accepted');
        assert_same($tB, (int) $a['tireman_id']);
        assert_contains("Tireman changed. {$tag} Tireman Bravo is now assigned.", $view($r1));

        $post($r5, ['_action' => 'reassign', 'expected_tireman_id' => (string) $tA, 'tireman_id' => (string) $tB]);
        $a = $row($r5);
        assert_same('accepted', $a['status']);
        assert_same($tB, (int) $a['tireman_id']);
        assert_same($admin1, (int) $a['admin_id'], 'admin_id moves to the admin who reassigned');
        assert_true($a['updated_at'] !== '2020-01-01 00:00:00');

        // ============ COMPLETE ============
        $post($r1, ['_action' => 'complete']);
        $c = $row($r1);
        assert_same('completed', $c['status']);
        assert_same($tB, (int) $c['tireman_id'], 'the Tireman stays on the completed request');
        assert_same($admin1, (int) $c['admin_id']);
        $d = $view($r1);
        assert_contains('Request marked as completed.', $d);
        $d = $view($r1);
        assert_contains('badge--completed">Completed<', $d);
        assert_contains('It is final and cannot be changed.', $d);
        assert_same(1, substr_count($d, 'method="post"'), 'a completed request offers no form except logout');
        assert_contains("{$tag} Tireman Bravo", $d, 'the historical Tireman is still shown');

        // Crafted POSTs cannot move a final request.
        $snap = $row($r1);
        foreach ([
            ['_action' => 'reject', 'expected_status' => 'accepted'],
            ['_action' => 'reject', 'expected_status' => 'completed'],
            ['_action' => 'reject', 'expected_status' => 'pending'],
            ['_action' => 'complete'],
            ['_action' => 'accept', 'tireman_id' => (string) $tA],
            ['_action' => 'reassign', 'expected_tireman_id' => (string) $tB, 'tireman_id' => (string) $tA],
        ] as $fields) {
            $post($r1, $fields);
            assert_same($snap, $row($r1), 'completed is final: ' . json_encode($fields));
        }

        // ============ REJECT: pending -> rejected ============
        $post($r2, ['_action' => 'reject', 'expected_status' => 'pending']);
        $x = $row($r2);
        assert_same('rejected', $x['status']);
        assert_null($x['tireman_id'], 'no Tireman was ever assigned');
        assert_same($admin1, (int) $x['admin_id']);
        assert_contains('Request rejected.', $view($r2));
        $d = $view($r2);
        assert_same(1, substr_count($d, 'method="post"'), 'a rejected request offers no form except logout');
        $snap = $row($r2);
        $post($r2, ['_action' => 'reject', 'expected_status' => 'pending']);
        $post($r2, ['_action' => 'accept', 'tireman_id' => (string) $tA]);
        assert_same($snap, $row($r2), 'rejected is final');

        // ============ REJECT: accepted -> rejected keeps the Tireman ============
        $post($r3, ['_action' => 'accept', 'tireman_id' => (string) $tA]);
        assert_same('accepted', $row($r3)['status']);
        $post($r3, ['_action' => 'reject', 'expected_status' => 'accepted']);
        $x = $row($r3);
        assert_same('rejected', $x['status']);
        assert_same($tA, (int) $x['tireman_id'], 'the assigned Tireman is kept as history');
        $snap = $row($r3);
        $post($r3, ['_action' => 'complete']);
        assert_same($snap, $row($r3), 'a rejected request cannot be completed');

        // ============ STALE: another admin changed the request after this page loaded ============
        $view($r4); // admin 1 sees it pending...
        $pdo->prepare("UPDATE service_requests SET status = 'accepted', tireman_id = ?, admin_id = ?, updated_at = '2020-01-02 00:00:00' WHERE request_id = ?")
            ->execute([$tB, $admin2, $r4]);   // ...admin 2 accepts it meanwhile
        $snap = $row($r4);
        $post($r4, ['_action' => 'reject', 'expected_status' => 'pending']);
        assert_contains('This request changed. Reload the page and try again.', $view($r4));
        $post($r4, ['_action' => 'accept', 'tireman_id' => (string) $tA]);
        assert_contains('This request changed', $view($r4));
        assert_same($snap, $row($r4), 'the newer state (accepted by admin 2 with Tireman B) is not overwritten');

        // ============ LEGACY: accepted with no Tireman — assign before completing ============
        $d = $view($r6);
        assert_contains('badge--accepted">Accepted<', $d);
        assert_not_contains('name="_action" value="complete"', $d, 'completion is not offered without a Tireman');
        assert_contains('A Tireman must be assigned before this request can be completed.', $d);
        assert_contains('name="_action" value="reassign"', $d, 'the admin can assign one');
        assert_contains('name="expected_tireman_id" value=""', $d);
        assert_contains('Assign Tireman', $d);
        $snap = $row($r6);
        $post($r6, ['_action' => 'complete']);
        assert_contains('Assign a Tireman before completing this request.', $view($r6));
        assert_same($snap, $row($r6), 'a crafted complete POST changes nothing');

        $post($r6, ['_action' => 'reassign', 'expected_tireman_id' => '', 'tireman_id' => (string) $tB]);
        $x = $row($r6);
        assert_same('accepted', $x['status']);
        assert_same($tB, (int) $x['tireman_id'], 'the legacy request now has a Tireman');
        assert_contains('name="_action" value="complete"', $view($r6), 'completion is offered once a Tireman is assigned');
        $post($r6, ['_action' => 'complete']);
        $x = $row($r6);
        assert_same('completed', $x['status']);
        assert_same($tB, (int) $x['tireman_id']);

        // ============ Tireman history: deactivate Tireman Alpha ============
        $server->request('/vulcatrack/admin/tiremen.php', ['_csrf' => $tok(), '_action' => 'deactivate', 'tireman_id' => (string) $tA]);
        assert_same(0, (int) $pdo->query('SELECT is_active FROM tiremen WHERE tireman_id = ' . $tA)->fetchColumn());
        $d = $view($r3);
        assert_same(200, $server->request($VIEW . '?id=' . $r3)['status']);
        assert_contains("{$tag} Tireman Alpha", $d, 'a deactivated Tireman still shows on their past request');
        assert_contains('badge--inactive">Inactive<', $d);
        $d = $view($rB);
        assert_not_contains('<option value="' . $tA . '">', $d, 'a deactivated Tireman is no longer offered');
        $snap = $row($rB);
        $post($rB, ['_action' => 'accept', 'tireman_id' => (string) $tA]);
        assert_same($snap, $row($rB), 'a deactivated Tireman cannot be assigned by a crafted POST');

        // ============ list reflects the new states ============
        assert_contains('rescue-view.php?id=' . $r1 . '"', $server->request($LIST . '?status=completed')['body']);
        assert_contains('rescue-view.php?id=' . $r2 . '"', $server->request($LIST . '?status=rejected')['body']);
        assert_contains('rescue-view.php?id=' . $r5 . '"', $server->request($LIST . '?status=accepted')['body']);

        // ============ CUSTOMER view of the results ============
        $home = $server->request('/vulcatrack/admin/index.php');
        $server->request('/vulcatrack/admin/logout.php', ['_csrf' => HttpServer::csrfToken($home['body'])]);
        $login = $server->request('/vulcatrack/login.php');
        $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']), 'email' => $emailA, 'password' => $password]);
        $booking = fn (int $id) => $server->request('/vulcatrack/customer/booking.php?id=' . $id);

        $b = $booking($r5)['body'];   // accepted + Tireman Bravo
        assert_contains('Tireman is on the way', $b, 'accepted + assigned shows the on-the-way wording');
        assert_contains("{$tag} Tireman Bravo", $b);

        $b = $booking($r1)['body'];   // completed, Tireman Bravo kept
        assert_contains('Completed', $b);
        assert_contains('This service has been completed.', $b);
        assert_contains("Serviced by {$tag} Tireman Bravo.", $b, 'the completed request keeps the historical Tireman');
        assert_not_contains('Tireman is on the way', $b, 'a completed request never says "on the way"');
        $assertCleanHtml($b, 'customer/booking.php (completed)');

        $b = $booking($r3)['body'];   // rejected after acceptance, Tireman Alpha kept
        assert_contains('Request declined', $b);
        assert_not_contains('Tireman is on the way', $b, 'a rejected request never says "on the way"');
        assert_not_contains('Serviced by', $b);

        $b = $booking($r2)['body'];   // rejected from pending
        assert_contains('Request declined', $b);
        assert_not_contains('Tireman is on the way', $b);

        $b = $booking($r4)['body'];   // accepted by admin 2 with Tireman Bravo
        assert_contains('Tireman is on the way', $b);

        $r = $booking($rB);
        assert_same(404, $r['status'], "customer A still cannot open customer B's request");

        $list = $server->request('/vulcatrack/customer/bookings.php')['body'];
        assert_contains('Completed', $list);
        assert_contains('Request declined', $list);
        assert_not_contains('booking.php?id=' . $rB . '"', $list);

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
