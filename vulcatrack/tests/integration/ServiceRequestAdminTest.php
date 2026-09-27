<?php
/**
 * Integration tests for the Phase 6.2 admin reads on ServiceRequestRepository
 * (listForAdmin / findForAdmin). Every test runs inside a rolled-back
 * transaction.
 *
 * The app has no status-changing code yet, so statuses / assignments are set
 * here with direct test-only SQL to exercise the read paths.
 *
 * Also re-proves the customer ownership boundary: adding admin reads must not
 * make any *ForCustomer method global.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\AdminRepository;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Repository\TiremanRepository;
use VulcaTrack\Repository\VehicleRepository;

/**
 * Seed two customers with one request per status (plus a second pending one),
 * with fixed requested_at values so ordering is deterministic.
 *
 * @return array<string,int> ids by key
 */
function sra_seed(\PDO $pdo): array
{
    $customers = new CustomerRepository($pdo);
    $vehicles  = new VehicleRepository($pdo);
    $requests  = new ServiceRequestRepository($pdo);

    $ids = [];
    $ids['custA'] = $customers->create('SRA Alice', TestDb::email('sra-a'), '0917 111 1111', Password::hash('password123'));
    $ids['custB'] = $customers->create('SRA Bob', TestDb::email('sra-b'), '0917 222 2222', Password::hash('password123'));
    $ids['vehA']  = $vehicles->create($ids['custA'], 'SRA-A1', 'motorcycle', 'Honda', 'Click');
    $ids['vehB']  = $vehicles->create($ids['custB'], 'SRA-B1', 'car', null, null);
    $ids['admin'] = (new AdminRepository($pdo))->create('SRA Admin', TestDb::email('sra-admin'), Password::hash('password123'));
    $ids['tireman'] = (new TiremanRepository($pdo))->create('SRA Tireman', '0918 333 3333');

    $rows = [
        // key          customer  vehicle  status       requested_at
        ['pendingOld', 'custA', 'vehA', 'pending',   '2026-01-01 08:00:00'],
        ['pendingNew', 'custB', 'vehB', 'pending',   '2026-01-03 08:00:00'],
        ['accepted',   'custA', 'vehA', 'accepted',  '2026-01-02 08:00:00'],
        ['rejected',   'custB', 'vehB', 'rejected',  '2026-01-02 09:00:00'],
        ['completed',  'custA', 'vehA', 'completed', '2026-01-02 10:00:00'],
    ];
    $set = $pdo->prepare('UPDATE service_requests SET status = ?, requested_at = ? WHERE request_id = ?');
    foreach ($rows as [$key, $cust, $veh, $status, $at]) {
        $ids[$key] = $requests->createPending($ids[$cust], $ids[$veh], "SRA problem {$key}", 14.95, 120.89, 12);
        $set->execute([$status, $at, $ids[$key]]);
    }
    // The accepted + completed requests have a Tireman and a handling admin.
    $pdo->prepare('UPDATE service_requests SET tireman_id = ?, admin_id = ? WHERE request_id IN (?, ?)')
        ->execute([$ids['tireman'], $ids['admin'], $ids['accepted'], $ids['completed']]);

    return $ids;
}

/** @return array<int,int> the request ids in $rows that are ours, in order */
function sra_ours(array $rows, array $ids): array
{
    $ours = array_map('intval', array_intersect_key($ids, array_flip(['pendingOld', 'pendingNew', 'accepted', 'rejected', 'completed'])));
    return array_values(array_filter(
        array_map(fn ($r) => (int) $r['request_id'], $rows),
        fn ($id) => in_array($id, $ours, true)
    ));
}

test('ServiceRequestRepository::listForAdmin returns every customer\'s requests, newest first', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable');
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = sra_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);

        assert_same(
            [$ids['pendingNew'], $ids['completed'], $ids['rejected'], $ids['accepted'], $ids['pendingOld']],
            sra_ours($repo->listForAdmin(), $ids),
            'all statuses, both customers, newest requested_at first'
        );

        $rows = array_values(array_filter($repo->listForAdmin(), fn ($r) => (int) $r['request_id'] === $ids['accepted']));
        assert_count(1, $rows);
        assert_same('SRA Alice', $rows[0]['customer_name']);
        assert_same('SRA-A1', $rows[0]['plate_number']);
        assert_same('SRA Tireman', $rows[0]['tireman_name']);
        assert_same('accepted', $rows[0]['status']);
    });
});

test('ServiceRequestRepository::listForAdmin filters by each status and rejects an unknown one', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = sra_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);

        assert_same([$ids['pendingNew'], $ids['pendingOld']], sra_ours($repo->listForAdmin('pending'), $ids));
        assert_same([$ids['accepted']], sra_ours($repo->listForAdmin('accepted'), $ids));
        assert_same([$ids['rejected']], sra_ours($repo->listForAdmin('rejected'), $ids));
        assert_same([$ids['completed']], sra_ours($repo->listForAdmin('completed'), $ids));
        foreach (['pending', 'accepted', 'rejected', 'completed'] as $s) {
            foreach ($repo->listForAdmin($s) as $row) {
                assert_same($s, $row['status'], "the {$s} filter returns only {$s} rows");
            }
        }

        assert_throws(fn () => $repo->listForAdmin('cancelled'), \InvalidArgumentException::class);
        assert_throws(fn () => $repo->listForAdmin("pending' OR '1'='1"), \InvalidArgumentException::class);
    });
});

test('ServiceRequestRepository::findForAdmin joins customer, vehicle, Tireman and handling admin', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = sra_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);

        $row = $repo->findForAdmin($ids['accepted']);
        assert_not_null($row);
        assert_same('accepted', $row['status']);
        assert_same('SRA problem accepted', $row['problem_description']);
        assert_same('SRA Alice', $row['customer_name']);
        assert_same('0917 111 1111', $row['customer_contact']);
        assert_contains('sra-a', $row['customer_email']);
        assert_same('SRA-A1', $row['plate_number']);
        assert_same('Honda', $row['make']);
        assert_same('Click', $row['model']);
        assert_same('motorcycle', $row['vehicle_type']);
        assert_same('SRA Tireman', $row['tireman_name']);
        assert_same('0918 333 3333', $row['tireman_contact']);
        assert_same(1, (int) $row['tireman_active']);
        assert_same('SRA Admin', $row['admin_name']);
        assert_same(12, (int) $row['eta_minutes'], 'the stored ETA is returned as-is');
        assert_same('14.9500000', (string) $row['latitude']);

        // Unassigned / unhandled request from the other customer: NULL joins.
        $row = $repo->findForAdmin($ids['pendingNew']);
        assert_same('SRA Bob', $row['customer_name']);
        assert_null($row['tireman_id']);
        assert_null($row['tireman_name']);
        assert_null($row['admin_id']);
        assert_null($row['admin_name']);
        assert_null($row['make']);

        assert_null($repo->findForAdmin(2147483647), 'an unknown id returns null');
    });
});

test('admin reads leave the customer methods ownership-scoped', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $ids = sra_seed($pdo);
        $repo = new ServiceRequestRepository($pdo);

        // Admin sees both customers' requests...
        assert_not_null($repo->findForAdmin($ids['pendingOld']));
        assert_not_null($repo->findForAdmin($ids['pendingNew']));

        // ...but each customer still only sees their own.
        assert_not_null($repo->findForCustomer($ids['pendingOld'], $ids['custA']));
        assert_null($repo->findForCustomer($ids['pendingOld'], $ids['custB']), 'B cannot read A\'s request');
        assert_null($repo->findForCustomer($ids['pendingNew'], $ids['custA']), 'A cannot read B\'s request');
        assert_count(3, $repo->listForCustomer($ids['custA']));
        assert_count(2, $repo->listForCustomer($ids['custB']));
        foreach ($repo->listForCustomer($ids['custB']) as $r) {
            assert_true(in_array((int) $r['request_id'], [$ids['pendingNew'], $ids['rejected']], true), 'B lists only B\'s requests');
        }
        assert_same($ids['pendingNew'], (int) $repo->latestForCustomer($ids['custB'])['request_id']);
        assert_same(2, $repo->countOpenForCustomer($ids['custA']), 'A: pendingOld + accepted');
        assert_same(1, $repo->countOpenForCustomer($ids['custB']), 'B: pendingNew only');
    });
});
