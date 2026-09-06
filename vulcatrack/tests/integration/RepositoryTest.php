<?php
/**
 * Integration tests -- the Phase 3/4 repositories against the live database.
 *
 * Every test runs inside a transaction that is rolled back, so the test
 * database is left untouched. These lock in the customer-scoped behaviour that
 * Phase 5 must not regress: ownership isolation on reads and writes, soft
 * delete, and the always-pending / frozen-ETA OTG creation path.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Auth\Password;
use VulcaTrack\Repository\AdminRepository;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Repository\VehicleRepository;

/** Insert a throwaway customer inside the current transaction; return its id. */
function seed_customer(\PDO $pdo, string $name = 'Test Customer'): int
{
    $repo = new CustomerRepository($pdo);
    return $repo->create($name, TestDb::email('cust'), '09170000000', Password::hash('password123'));
}

function seed_admin(\PDO $pdo, string $name = 'Test Admin'): int
{
    $repo = new AdminRepository($pdo);
    return $repo->create($name, TestDb::email('admin'), Password::hash('password123'));
}

test('CustomerRepository create / findByEmail / findById / emailExists round-trip', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new CustomerRepository($pdo);
        $email = TestDb::email('rt');
        $id = $repo->create('Round Trip', $email, '09171234567', Password::hash('password123'));
        assert_true($id > 0);

        assert_true($repo->emailExists($email));
        assert_false($repo->emailExists(TestDb::email('nope')));

        $byEmail = $repo->findByEmail($email);
        assert_same($id, (int) $byEmail['customer_id']);

        $byId = $repo->findById($id);
        assert_same('09171234567', $byId['contact_number']);
        assert_same('Round Trip', $byId['full_name']);
    });
});

test('CustomerRepository::create surfaces the duplicate-email unique violation (1062)', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new CustomerRepository($pdo);
        $email = TestDb::email('dupe');
        $repo->create('First', $email, '0917', Password::hash('password123'));
        assert_throws(
            fn () => $repo->create('Second', $email, '0918', Password::hash('password123')),
            \PDOException::class
        );
    });
});

test('customers.email and admins.email are independent (Decision 42)', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $shared = TestDb::email('shared');
        $cid = (new CustomerRepository($pdo))->create('Person C', $shared, '0917', Password::hash('password123'));
        $aid = (new AdminRepository($pdo))->create('Person A', $shared, Password::hash('password123'));
        assert_true($cid > 0 && $aid > 0, 'the same address may exist once in each table');
    });
});

test('VehicleRepository scopes every read and write to the owning customer', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new VehicleRepository($pdo);
        $owner = seed_customer($pdo, 'Owner');
        $other = seed_customer($pdo, 'Other');

        $vid = $repo->create($owner, 'ABC-123', 'car', 'Toyota', 'Vios');
        assert_true($vid > 0);

        // Owner sees it; the other customer does not.
        assert_not_null($repo->findForCustomer($vid, $owner));
        assert_null($repo->findForCustomer($vid, $other), 'ownership isolation on read');

        // The other customer cannot mutate it.
        $repo->update($vid, $other, 'HACK-000', null, null, null);
        assert_same('ABC-123', $repo->findForCustomer($vid, $owner)['plate_number'], 'ownership isolation on update');

        $repo->setActive($vid, $other, false);
        assert_same(1, (int) $repo->findForCustomer($vid, $owner)['is_active'], 'ownership isolation on setActive');
    });
});

test('VehicleRepository soft delete hides from the active list but keeps the row', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new VehicleRepository($pdo);
        $cust = seed_customer($pdo);
        $vid = $repo->create($cust, 'SOFT-1', null, null, null);

        assert_same(1, $repo->countActiveForCustomer($cust));
        $repo->setActive($vid, $cust, false);
        assert_same(0, $repo->countActiveForCustomer($cust));
        assert_count(0, $repo->listForCustomer($cust, false));
        assert_count(1, $repo->listForCustomer($cust, true), 'the row still exists, just inactive');

        $repo->setActive($vid, $cust, true);
        assert_same(1, $repo->countActiveForCustomer($cust));
    });
});

test('ServiceRequestRepository::createPending always writes status=pending with a frozen ETA', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $cust = seed_customer($pdo);
        $vid = (new VehicleRepository($pdo))->create($cust, 'OTG-1', 'motorcycle', null, null);
        $repo = new ServiceRequestRepository($pdo);

        $rid = $repo->createPending($cust, $vid, 'Flat rear tyre', 14.973, 120.905, 17);
        assert_true($rid > 0);

        $row = $repo->findForCustomer($rid, $cust);
        assert_same('pending', $row['status'], 'OTG requests are created pending (Decision, Phase 4)');
        assert_same(17, (int) $row['eta_minutes'], 'the ETA is stored exactly as passed -- a frozen snapshot');
        assert_null($row['tireman_id'], 'no Tireman is assigned at creation');

        // admin_id / tireman_id both start NULL -- assert against the raw row.
        $raw = $pdo->query('SELECT admin_id, tireman_id, status FROM service_requests WHERE request_id = ' . (int) $rid)->fetch();
        assert_null($raw['admin_id'], 'admin_id is NULL until an admin picks up the request');
        assert_null($raw['tireman_id']);
    });
});

test('ServiceRequestRepository read methods are customer-scoped', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $mine = seed_customer($pdo, 'Mine');
        $theirs = seed_customer($pdo, 'Theirs');
        $vid = (new VehicleRepository($pdo))->create($mine, 'SCOPE-1', null, null, null);
        $repo = new ServiceRequestRepository($pdo);
        $rid = $repo->createPending($mine, $vid, 'Problem', 14.9, 120.9, 10);

        assert_not_null($repo->findForCustomer($rid, $mine));
        assert_null($repo->findForCustomer($rid, $theirs), 'another customer cannot read the request');
        assert_count(1, $repo->listForCustomer($mine));
        assert_count(0, $repo->listForCustomer($theirs));
        assert_same(1, $repo->countOpenForCustomer($mine));
    });
});

test('the frozen ETA is never recomputed on read (no live ETA)', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $cust = seed_customer($pdo);
        $vid = (new VehicleRepository($pdo))->create($cust, 'FROZEN-1', null, null, null);
        $repo = new ServiceRequestRepository($pdo);
        // An ETA that could never be "recalculated" to this value -- proving it is stored, not derived.
        $rid = $repo->createPending($cust, $vid, 'x', 14.9466, 120.8929, 999);
        assert_same(999, (int) $repo->findForCustomer($rid, $cust)['eta_minutes']);
        assert_same(999, (int) $repo->latestForCustomer($cust)['eta_minutes']);
    });
});
