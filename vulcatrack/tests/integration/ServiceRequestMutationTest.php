<?php
/**
 * Integration tests for the Phase 6.3 admin mutations on
 * ServiceRequestRepository: accept(), reassign(), reject(), complete().
 * Every test runs inside a rolled-back transaction.
 *
 * Locks in the owner-approved rules: acceptance always assigns an ACTIVE
 * Tireman; reassignment only while accepted; pending/accepted -> rejected;
 * accepted -> completed; rejected / completed are final; admin_id = the last
 * admin who changed the request; the Tireman stays on final requests; and a
 * guarded UPDATE never overwrites a request that changed elsewhere.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\ServiceRequestRepository;

/**
 * Seed a customer + vehicle, two admins and three Tiremen (two active, one
 * inactive). Requests are made with srm_request().
 *
 * @return array<string,int>
 */
function srm_seed(\PDO $pdo): array
{
    $ids = [];
    $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
        ->execute(['SRM Customer', TestDb::email('srm'), '0917 000 0000', Password::hash('password123')]);
    $ids['cust'] = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO vehicles (customer_id, plate_number) VALUES (?,?)')->execute([$ids['cust'], 'SRM-1']);
    $ids['veh'] = (int) $pdo->lastInsertId();
    foreach (['admin1', 'admin2'] as $key) {
        $pdo->prepare('INSERT INTO admins (full_name, email, password_hash) VALUES (?,?,?)')
            ->execute(["SRM {$key}", TestDb::email('srm-' . $key), Password::hash('password123')]);
        $ids[$key] = (int) $pdo->lastInsertId();
    }
    foreach (['tA' => 1, 'tB' => 1, 'tOff' => 0] as $key => $active) {
        $pdo->prepare('INSERT INTO tiremen (name, contact_number, is_active) VALUES (?,?,?)')
            ->execute(["SRM {$key}", '0918 000 0000', $active]);
        $ids[$key] = (int) $pdo->lastInsertId();
    }
    return $ids;
}

/** A request in the given state with an old updated_at, so a later UPDATE is observable. */
function srm_request(\PDO $pdo, array $ids, string $status, ?int $tireman = null, ?int $admin = null): int
{
    $pdo->prepare(
        "INSERT INTO service_requests
             (customer_id, vehicle_id, admin_id, tireman_id, problem_description, latitude, longitude, eta_minutes, status, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?, '2020-01-01 00:00:00')"
    )->execute([$ids['cust'], $ids['veh'], $admin, $tireman, 'SRM problem', 14.95, 120.89, 12, $status]);
    return (int) $pdo->lastInsertId();
}

/** @return array<string,mixed> the raw row */
function srm_row(\PDO $pdo, int $id): array
{
    return $pdo->query('SELECT * FROM service_requests WHERE request_id = ' . $id)->fetch(\PDO::FETCH_ASSOC);
}

test('accept: pending + active Tireman -> accepted, Tireman + acting admin stored, updated_at set', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable');
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srm_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);
        $rid = srm_request($pdo, $ids, 'pending');

        assert_true($repo->accept($rid, $ids['tA'], $ids['admin1']));
        $row = srm_row($pdo, $rid);
        assert_same('accepted', $row['status']);
        assert_same($ids['tA'], (int) $row['tireman_id']);
        assert_same($ids['admin1'], (int) $row['admin_id']);
        assert_true($row['updated_at'] !== '2020-01-01 00:00:00', 'updated_at is set');
        assert_same(12, (int) $row['eta_minutes'], 'the frozen ETA is untouched');
    });
});

test('accept: inactive / unknown Tireman or a non-pending request changes nothing', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srm_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);

        $rid = srm_request($pdo, $ids, 'pending');
        $before = srm_row($pdo, $rid);
        assert_false($repo->accept($rid, $ids['tOff'], $ids['admin1']), 'an inactive Tireman cannot be assigned');
        assert_false($repo->accept($rid, 2147483647, $ids['admin1']), 'an unknown Tireman cannot be assigned');
        assert_same($before, srm_row($pdo, $rid), 'the pending request is unchanged');

        foreach (['accepted', 'rejected', 'completed'] as $status) {
            $other = srm_request($pdo, $ids, $status, $status === 'pending' ? null : $ids['tB'], $ids['admin2']);
            $before = srm_row($pdo, $other);
            assert_false($repo->accept($other, $ids['tA'], $ids['admin1']), "a {$status} request cannot be accepted");
            assert_same($before, srm_row($pdo, $other), "the {$status} request is unchanged (newer state not overwritten)");
        }
    });
});

test('reassign: accepted -> a different active Tireman; status stays accepted; admin + updated_at change', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srm_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);
        $rid = srm_request($pdo, $ids, 'accepted', $ids['tA'], $ids['admin2']);

        assert_true($repo->reassign($rid, $ids['tA'], $ids['tB'], $ids['admin1']));
        $row = srm_row($pdo, $rid);
        assert_same('accepted', $row['status'], 'reassignment never changes the status');
        assert_same($ids['tB'], (int) $row['tireman_id']);
        assert_same($ids['admin1'], (int) $row['admin_id'], 'admin_id = the last admin who changed it');
        assert_true($row['updated_at'] !== '2020-01-01 00:00:00');

        // Legacy data: an accepted request with no Tireman can be given one (expected = none).
        $legacy = srm_request($pdo, $ids, 'accepted');
        assert_true($repo->reassign($legacy, null, $ids['tA'], $ids['admin1']));
        assert_same($ids['tA'], (int) srm_row($pdo, $legacy)['tireman_id']);
    });
});

test('reassign: refuses same / inactive / unknown Tireman, a stale expected Tireman, and non-accepted requests', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srm_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);
        $rid = srm_request($pdo, $ids, 'accepted', $ids['tA'], $ids['admin2']);
        $before = srm_row($pdo, $rid);

        assert_false($repo->reassign($rid, $ids['tA'], $ids['tA'], $ids['admin1']), 'already assigned');
        assert_false($repo->reassign($rid, $ids['tA'], $ids['tOff'], $ids['admin1']), 'inactive Tireman');
        assert_false($repo->reassign($rid, $ids['tA'], 2147483647, $ids['admin1']), 'unknown Tireman');
        // The admin saw Tireman B (or none), but the request is now on A: do not overwrite.
        assert_false($repo->reassign($rid, $ids['tB'], $ids['tA'], $ids['admin1']), 'stale expected Tireman');
        assert_false($repo->reassign($rid, null, $ids['tB'], $ids['admin1']), 'stale expected Tireman (none)');
        assert_same($before, srm_row($pdo, $rid), 'nothing changed');

        foreach (['pending' => null, 'rejected' => $ids['tA'], 'completed' => $ids['tA']] as $status => $t) {
            $other = srm_request($pdo, $ids, $status, $t, $ids['admin2']);
            $snap = srm_row($pdo, $other);
            assert_false($repo->reassign($other, $t, $ids['tB'], $ids['admin1']), "a {$status} request cannot be reassigned");
            assert_same($snap, srm_row($pdo, $other));
        }
    });
});

test('reject: pending and accepted can be rejected; the Tireman is kept; final requests cannot', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srm_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);

        $p = srm_request($pdo, $ids, 'pending');
        assert_true($repo->reject($p, 'pending', $ids['admin1']));
        $row = srm_row($pdo, $p);
        assert_same('rejected', $row['status']);
        assert_null($row['tireman_id'], 'no Tireman was ever assigned');
        assert_same($ids['admin1'], (int) $row['admin_id']);
        assert_true($row['updated_at'] !== '2020-01-01 00:00:00');

        $a = srm_request($pdo, $ids, 'accepted', $ids['tA'], $ids['admin2']);
        assert_true($repo->reject($a, 'accepted', $ids['admin1']));
        $row = srm_row($pdo, $a);
        assert_same('rejected', $row['status']);
        assert_same($ids['tA'], (int) $row['tireman_id'], 'the assigned Tireman is kept as history');
        assert_same($ids['admin1'], (int) $row['admin_id']);

        // Rejecting again: the caller can only claim pending/accepted, and the guard sees 'rejected'.
        $snap = srm_row($pdo, $a);
        assert_false($repo->reject($a, 'accepted', $ids['admin1']), 'a rejected request cannot be rejected again');
        assert_same($snap, srm_row($pdo, $a));
        assert_throws(fn () => $repo->reject($a, 'rejected', $ids['admin1']), \InvalidArgumentException::class);
        assert_throws(fn () => $repo->reject($a, 'completed', $ids['admin1']), \InvalidArgumentException::class);

        $c = srm_request($pdo, $ids, 'completed', $ids['tA'], $ids['admin2']);
        $snap = srm_row($pdo, $c);
        assert_false($repo->reject($c, 'accepted', $ids['admin1']), 'a completed request cannot be rejected');
        assert_false($repo->reject($c, 'pending', $ids['admin1']));
        assert_same($snap, srm_row($pdo, $c));
    });
});

test('reject: a stale expected status (request changed elsewhere) changes nothing', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srm_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);
        // The admin saw it pending, but another admin already accepted it.
        $rid = srm_request($pdo, $ids, 'accepted', $ids['tB'], $ids['admin2']);
        $snap = srm_row($pdo, $rid);
        assert_false($repo->reject($rid, 'pending', $ids['admin1']));
        assert_same($snap, srm_row($pdo, $rid), 'the newer accepted state is not overwritten');
    });
});

test('complete: accepted -> completed keeps the Tireman; nothing else can be completed', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srm_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);

        $a = srm_request($pdo, $ids, 'accepted', $ids['tB'], $ids['admin2']);
        assert_true($repo->complete($a, $ids['admin1']));
        $row = srm_row($pdo, $a);
        assert_same('completed', $row['status']);
        assert_same($ids['tB'], (int) $row['tireman_id'], 'the Tireman is kept on the completed request');
        assert_same($ids['admin1'], (int) $row['admin_id']);
        assert_true($row['updated_at'] !== '2020-01-01 00:00:00');

        $snap = srm_row($pdo, $a);
        assert_false($repo->complete($a, $ids['admin2']), 'a completed request cannot be completed again');
        assert_same($snap, srm_row($pdo, $a));

        foreach (['pending' => null, 'rejected' => $ids['tA']] as $status => $t) {
            $other = srm_request($pdo, $ids, $status, $t, $ids['admin2']);
            $snap = srm_row($pdo, $other);
            assert_false($repo->complete($other, $ids['admin1']), "a {$status} request cannot be completed");
            assert_same($snap, srm_row($pdo, $other));
        }
    });
});

test('complete: an accepted request with no Tireman (legacy data) cannot be completed', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srm_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);
        $legacy = srm_request($pdo, $ids, 'accepted', null, $ids['admin2']);
        $snap = srm_row($pdo, $legacy);

        assert_false($repo->complete($legacy, $ids['admin1']), 'completion requires an assigned Tireman');
        assert_same($snap, srm_row($pdo, $legacy), 'nothing changed');

        // Assign one first (the approved route), then completion works.
        assert_true($repo->reassign($legacy, null, $ids['tA'], $ids['admin1']));
        assert_true($repo->complete($legacy, $ids['admin1']));
        assert_same('completed', srm_row($pdo, $legacy)['status']);
        assert_same($ids['tA'], (int) srm_row($pdo, $legacy)['tireman_id']);
    });
});

test('complete: an assigned Tireman who was since deactivated does not block completion', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srm_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);
        // Accepted with Tireman A, who is then deactivated before the job is closed.
        $rid = srm_request($pdo, $ids, 'accepted', $ids['tA'], $ids['admin2']);
        $pdo->prepare('UPDATE tiremen SET is_active = 0 WHERE tireman_id = ?')->execute([$ids['tA']]);

        assert_true($repo->complete($rid, $ids['admin1']));
        $row = srm_row($pdo, $rid);
        assert_same('completed', $row['status']);
        assert_same($ids['tA'], (int) $row['tireman_id'], 'the historical (now inactive) Tireman is preserved');
        assert_same($ids['admin1'], (int) $row['admin_id']);
        assert_true($row['updated_at'] !== '2020-01-01 00:00:00');
    });
});

test('an accepted request stays visible to the admin (Accepted + All) through reassignment and Tireman deactivation', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srm_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);
        $rid = srm_request($pdo, $ids, 'pending');
        $inList = fn (?string $status) => in_array($rid, array_map(fn ($r) => (int) $r['request_id'], $repo->listForAdmin($status)), true);
        $visible = function (string $when) use ($repo, $inList, $rid, $ids): void {
            assert_true($inList('accepted'), "{$when}: listed under Accepted");
            assert_true($inList(null), "{$when}: listed under All");
            assert_false($inList('pending'), "{$when}: no longer under Pending (the default admin view)");
            assert_not_null($repo->findForAdmin($rid), "{$when}: admin detail loads");
            assert_same('accepted', $repo->findForCustomer($rid, $ids['cust'])['status'], "{$when}: the customer still sees it accepted");
        };

        assert_true($repo->accept($rid, $ids['tA'], $ids['admin1']));
        $visible('after accept');
        assert_true($repo->reassign($rid, $ids['tA'], $ids['tB'], $ids['admin2']));
        $visible('after reassign');
        $pdo->prepare('UPDATE tiremen SET is_active = 0 WHERE tireman_id = ?')->execute([$ids['tB']]);
        $visible('after the assigned Tireman is deactivated');
        $pdo->prepare('UPDATE vehicles SET is_active = 0 WHERE vehicle_id = ?')->execute([$ids['veh']]);
        $visible('after the vehicle is deactivated');
    });
});

test('a deactivated Tireman stays visible on requests they were assigned to', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = srm_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);
        $rid = srm_request($pdo, $ids, 'pending');
        assert_true($repo->accept($rid, $ids['tA'], $ids['admin1']));
        assert_true($repo->complete($rid, $ids['admin1']));

        $pdo->prepare('UPDATE tiremen SET is_active = 0 WHERE tireman_id = ?')->execute([$ids['tA']]);

        $admin = $repo->findForAdmin($rid);
        assert_same('SRM tA', $admin['tireman_name'], 'the admin still sees the historical Tireman');
        assert_same(0, (int) $admin['tireman_active']);
        $cust = $repo->findForCustomer($rid, $ids['cust']);
        assert_same('SRM tA', $cust['tireman_name'], 'the customer still sees the historical Tireman');

        // ...but the now-inactive Tireman cannot be picked for a new assignment.
        $next = srm_request($pdo, $ids, 'pending');
        assert_false($repo->accept($next, $ids['tA'], $ids['admin1']));
        assert_same('pending', srm_row($pdo, $next)['status']);
    });
});
