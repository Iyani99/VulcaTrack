<?php
/**
 * Customer My Vehicles + Add / Edit Vehicle over HTTP (Phase 7.4b-e1).
 *
 * Covers the behaviour the Figma restyle must not change -- ownership scoping
 * of edit / remove / restore, CSRF, soft remove / restore, Book a Rescue only
 * offering active vehicles -- plus the new server-side Vehicle Type rule (a
 * standard choice or the vehicle's own legacy value; blank stays allowed) and
 * the account navigation (My Vehicles under Profile, no Notifications).
 *
 * Two throwaway customers are seeded via PDO: A signs in, B only owns a
 * vehicle A must not be able to touch. Everything is deleted afterwards.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;

test('customer vehicles: ownership, CSRF, soft remove/restore, vehicle type rules, account nav', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable for the HTTP tests');

    $password = 'vehicles-password-123';
    $insertCustomer = $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)');
    $insertCustomer->execute(['Vehicle Owner A', $emailA = TestDb::email('veh-a'), '09170000001', Password::hash($password)]);
    $custA = (int) $pdo->lastInsertId();
    $insertCustomer->execute(['Vehicle Owner B', TestDb::email('veh-b'), '09170000002', Password::hash($password)]);
    $custB = (int) $pdo->lastInsertId();

    $insertVehicle = $pdo->prepare('INSERT INTO vehicles (customer_id, plate_number, vehicle_type, make, model, is_active) VALUES (?,?,?,?,?,?)');
    $insertVehicle->execute([$custB, 'BBB-1111', 'Sedan', 'Toyota', 'Vios', 1]);
    $vehB = (int) $pdo->lastInsertId();
    $insertVehicle->execute([$custB, 'BBB-2222', 'Sedan', null, null, 0]);
    $vehBRemoved = (int) $pdo->lastInsertId();
    $insertVehicle->execute([$custA, 'LEG-0001', 'Motorcycle', 'Honda', 'Wave', 1]); // pre-dropdown free text
    $vehLegacy = (int) $pdo->lastInsertId();

    $row = static function (int $id) use ($pdo): array {
        $s = $pdo->prepare('SELECT customer_id, plate_number, vehicle_type, make, model, is_active FROM vehicles WHERE vehicle_id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: [];
    };
    $countFor = static function (int $cid, string $plate) use ($pdo): int {
        $s = $pdo->prepare('SELECT COUNT(*) FROM vehicles WHERE customer_id = ? AND plate_number = ?');
        $s->execute([$cid, $plate]);
        return (int) $s->fetchColumn();
    };

    $server = new HttpServer(8697);
    $cleanup = function () use ($pdo, $custA, $custB): void {
        $pdo->prepare('DELETE FROM vehicles WHERE customer_id IN (?, ?)')->execute([$custA, $custB]);
        $pdo->prepare('DELETE FROM customers WHERE customer_id IN (?, ?)')->execute([$custA, $custB]);
    };

    try {
        $server->start();
        $login = $server->request('/vulcatrack/login.php');
        $r = $server->request('/vulcatrack/login.php', ['_csrf' => HttpServer::csrfToken($login['body']), 'email' => $emailA, 'password' => $password]);
        assert_same(302, $r['status'], 'customer A signs in');

        // --- navigation: My Vehicles lives under Profile ----------------------
        $list = $server->request('/vulcatrack/customer/vehicles.php');
        assert_same(200, $list['status']);
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error', 'Undefined '] as $bad) {
            assert_not_contains($bad, $list['body'], "PHP error text on customer/vehicles.php: {$bad}");
        }
        assert_true(preg_match('#<nav class="appnav"[^>]*>(.*?)</nav>#s', $list['body'], $top) === 1, 'the customer top nav renders');
        assert_not_contains('vehicles.php', $top[1], 'My Vehicles is no longer a top-level nav item');
        assert_contains('href="/vulcatrack/customer/profile.php" class="is-active" aria-current="page"', $top[1], 'Profile is the active top-level item');
        assert_true(preg_match('#<nav class="ac-nav"[^>]*>(.*?)</nav>#s', $list['body'], $acc) === 1, 'the account navigation renders');
        assert_contains('href="/vulcatrack/customer/vehicles.php" class="is-active" aria-current="page"', $acc[1], 'Your Vehicles is the active account item');
        assert_contains('href="/vulcatrack/customer/profile.php#security"', $acc[1], 'Security points at the profile password section');
        assert_contains('action="/vulcatrack/logout.php"', $acc[1], 'account Log out is the POST logout form');
        foreach (['Notifications', 'Tracking'] as $absent) {
            assert_not_contains($absent, $list['body'], "no {$absent} entry");
        }
        assert_contains('LEG-0001', $list['body'], 'A sees their own vehicle');
        assert_not_contains('BBB-1111', $list['body'], 'A never sees B\'s vehicles');
        $token = HttpServer::csrfToken($list['body']);

        $profile = $server->request('/vulcatrack/customer/profile.php');
        assert_contains('id="security"', $profile['body'], 'the profile password section is the Security anchor target');

        // --- add: a standard type, a blank type, a forged / oversized type --
        $r = $server->request('/vulcatrack/customer/vehicle-edit.php', ['_csrf' => $token, 'plate_number' => 'NEW-0001', 'vehicle_type' => 'Van / MPV', 'make' => 'Toyota', 'model' => 'Innova']);
        assert_same(302, $r['status'], 'a valid add redirects');
        assert_contains('/customer/vehicles.php', (string) $r['location']);
        assert_same(1, $countFor($custA, 'NEW-0001'));

        $r = $server->request('/vulcatrack/customer/vehicle-edit.php', ['_csrf' => $token, 'plate_number' => 'NEW-0002', 'vehicle_type' => '', 'make' => '', 'model' => '']);
        assert_same(302, $r['status'], 'vehicle type stays optional');
        $s = $pdo->prepare('SELECT vehicle_type FROM vehicles WHERE customer_id = ? AND plate_number = ?');
        $s->execute([$custA, 'NEW-0002']);
        assert_null($s->fetchColumn(), 'a blank type is stored as NULL');

        foreach (['Spaceship', str_repeat('x', 41), 'Motorcycle'] as $forged) {
            $r = $server->request('/vulcatrack/customer/vehicle-edit.php', ['_csrf' => $token, 'plate_number' => 'BAD-0001', 'vehicle_type' => $forged]);
            assert_same(200, $r['status'], 'a forged type re-renders the form');
            assert_contains('err-vehicle_type', $r['body'], 'the type error is shown on the field');
            assert_same(0, $countFor($custA, 'BAD-0001'), 'nothing is saved for a forged type: ' . substr($forged, 0, 12));
        }

        // --- edit: legacy value kept, then changed to a standard choice ------
        $edit = $server->request('/vulcatrack/customer/vehicle-edit.php?id=' . $vehLegacy);
        assert_same(200, $edit['status']);
        assert_contains('<option value="Motorcycle" selected>Motorcycle (current)</option>', $edit['body'], 'the legacy type is offered and selected');
        $r = $server->request('/vulcatrack/customer/vehicle-edit.php?id=' . $vehLegacy, ['_csrf' => $token, 'plate_number' => 'LEG-0001', 'vehicle_type' => 'Motorcycle', 'make' => 'Honda', 'model' => 'Wave 110']);
        assert_same(302, $r['status'], 'saving with the legacy type succeeds');
        assert_same('Motorcycle', $row($vehLegacy)['vehicle_type'], 'the legacy type is preserved');
        assert_same('Wave 110', $row($vehLegacy)['model']);
        $r = $server->request('/vulcatrack/customer/vehicle-edit.php?id=' . $vehLegacy, ['_csrf' => $token, 'plate_number' => 'LEG-0001', 'vehicle_type' => 'Motorcycle / Scooter', 'make' => 'Honda', 'model' => 'Wave 110']);
        assert_same(302, $r['status']);
        assert_same('Motorcycle / Scooter', $row($vehLegacy)['vehicle_type'], 'a standard choice replaces it');

        // --- ownership: A cannot view / edit / remove / restore B's vehicles --
        $r = $server->request('/vulcatrack/customer/vehicle-edit.php?id=' . $vehB);
        assert_same(404, $r['status'], 'B\'s vehicle edit page is not found for A');
        assert_not_contains('BBB-1111', $r['body']);
        $r = $server->request('/vulcatrack/customer/vehicle-edit.php?id=' . $vehB, ['_csrf' => $token, 'plate_number' => 'HIJACK', 'vehicle_type' => 'SUV']);
        assert_same(404, $r['status'], 'A cannot post an edit to B\'s vehicle');
        assert_same('BBB-1111', $row($vehB)['plate_number']);
        $r = $server->request('/vulcatrack/customer/vehicles.php', ['_csrf' => $token, '_action' => 'deactivate', 'vehicle_id' => (string) $vehB]);
        assert_contains('Vehicle not found.', $r['body'], 'A cannot remove B\'s vehicle');
        assert_same(1, (int) $row($vehB)['is_active']);
        $r = $server->request('/vulcatrack/customer/vehicles.php', ['_csrf' => $token, '_action' => 'reactivate', 'vehicle_id' => (string) $vehBRemoved]);
        assert_contains('Vehicle not found.', $r['body'], 'A cannot restore B\'s vehicle');
        assert_same(0, (int) $row($vehBRemoved)['is_active']);
        assert_same($custB, (int) $row($vehB)['customer_id']);

        // --- CSRF ------------------------------------------------------------
        $r = $server->request('/vulcatrack/customer/vehicles.php', ['_csrf' => 'bogus', '_action' => 'deactivate', 'vehicle_id' => (string) $vehLegacy]);
        assert_contains('Your session expired', $r['body'], 'a bad token is refused');
        assert_same(1, (int) $row($vehLegacy)['is_active'], 'a bad-token remove changes nothing');
        $r = $server->request('/vulcatrack/customer/vehicle-edit.php', ['_csrf' => 'bogus', 'plate_number' => 'CSRF-001']);
        assert_same(200, $r['status']);
        assert_same(0, $countFor($custA, 'CSRF-001'), 'a bad-token add saves nothing');

        // --- soft remove / restore + Book a Rescue offers active vehicles only -
        $radio = 'name="vehicle_id" value="' . $vehLegacy . '"';
        assert_contains($radio, $server->request('/vulcatrack/customer/rescue.php')['body'], 'an active vehicle is bookable');
        $r = $server->request('/vulcatrack/customer/vehicles.php', ['_csrf' => $token, '_action' => 'deactivate', 'vehicle_id' => (string) $vehLegacy]);
        assert_contains('Vehicle removed from your active list.', $r['body']);
        assert_same(1, preg_match('#id="vh-removed".*LEG-0001#s', $r['body']), 'the removed vehicle is listed under Removed Vehicles');
        assert_same(0, (int) $row($vehLegacy)['is_active'], 'remove is a soft delete');
        assert_not_contains($radio, $server->request('/vulcatrack/customer/rescue.php')['body'], 'a removed vehicle is not bookable');
        $r = $server->request('/vulcatrack/customer/vehicles.php', ['_csrf' => $token, '_action' => 'reactivate', 'vehicle_id' => (string) $vehLegacy]);
        assert_contains('Vehicle restored.', $r['body']);
        assert_same(1, (int) $row($vehLegacy)['is_active']);
        assert_contains($radio, $server->request('/vulcatrack/customer/rescue.php')['body'], 'a restored vehicle is bookable again');

        $stderr = $server->serverStderr();
        foreach (['PHP Warning', 'PHP Notice', 'PHP Deprecated', 'PHP Fatal'] as $bad) {
            assert_not_contains($bad, $stderr, "php -S stderr contained: {$bad}\n{$stderr}");
        }
    } finally {
        $server->stop();
        $cleanup();
    }
});
