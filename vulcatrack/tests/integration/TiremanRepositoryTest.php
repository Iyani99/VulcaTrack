<?php
/**
 * Integration tests for VulcaTrack\Repository\TiremanRepository against the
 * live `tiremen` table (Phase 6, Chunk 6.1). Every test runs inside a
 * transaction that is rolled back, so the database is left untouched.
 *
 * Locks in: identity/contact-only records, active/inactive/all listing,
 * listActive() for future assignment (inactive never offered — Decision 28),
 * soft activate/deactivate (no delete), and explicit updated_at maintenance
 * (the column has no ON UPDATE clause).
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Repository\TiremanRepository;

/** Insert a Tireman with an old updated_at so a later UPDATE is observable. */
function seed_old_tireman(\PDO $pdo, string $name, string $contact, int $active = 1): int
{
    $pdo->prepare(
        "INSERT INTO tiremen (name, contact_number, is_active, created_at, updated_at)
         VALUES (?, ?, ?, '2020-01-01 00:00:00', '2020-01-01 00:00:00')"
    )->execute([$name, $contact, $active]);
    return (int) $pdo->lastInsertId();
}

test('TiremanRepository creates an active Tireman and reads it back', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable');
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new TiremanRepository($pdo);
        $id = $repo->create('Juan Dela Cruz', '0917 123 4567');
        assert_true($id > 0);

        $row = $repo->findById($id);
        assert_not_null($row);
        assert_same($id, $row['tireman_id']);
        assert_same('Juan Dela Cruz', $row['name']);
        assert_same('0917 123 4567', $row['contact_number']);
        assert_same(1, $row['is_active'], 'a new Tireman is active');
        assert_not_null($row['created_at']);
        assert_not_null($row['updated_at']);
        assert_same(
            ['tireman_id', 'name', 'contact_number', 'is_active', 'created_at', 'updated_at'],
            array_keys($row),
            'only the approved identity/contact columns are returned'
        );
    });
});

test('TiremanRepository::findById returns null for an unknown id', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        assert_null((new TiremanRepository($pdo))->findById(2147483647));
    });
});

test('TiremanRepository::update changes name + contact, keeps is_active, bumps updated_at', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new TiremanRepository($pdo);
        $id = seed_old_tireman($pdo, 'Old Name', '111', 0);

        $repo->update($id, 'New Name', '0918 000 0000');

        $row = $repo->findById($id);
        assert_same('New Name', $row['name']);
        assert_same('0918 000 0000', $row['contact_number']);
        assert_same(0, $row['is_active'], 'update() never touches is_active');
        assert_true($row['updated_at'] !== '2020-01-01 00:00:00', 'update() sets updated_at');
        assert_same('2020-01-01 00:00:00', $row['created_at'], 'created_at is unchanged');
    });
});

test('TiremanRepository::setActive deactivates and reactivates (soft, no delete) and bumps updated_at', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new TiremanRepository($pdo);
        $id = seed_old_tireman($pdo, 'Toggle Tireman', '222');

        $repo->setActive($id, false);
        $row = $repo->findById($id);
        assert_not_null($row, 'deactivation keeps the row');
        assert_same(0, $row['is_active']);
        assert_true($row['updated_at'] !== '2020-01-01 00:00:00', 'setActive() sets updated_at');

        $repo->setActive($id, true);
        assert_same(1, $repo->findById($id)['is_active']);
    });
});

test('TiremanRepository::list filters active / inactive / all; listActive excludes inactive', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new TiremanRepository($pdo);
        $a = $repo->create('ZZTEST Active A', '333');
        $b = $repo->create('ZZTEST Active B', '444');
        $c = $repo->create('ZZTEST Inactive C', '555');
        $repo->setActive($c, false);

        $ids = fn (array $rows) => array_values(array_intersect(
            array_map(fn ($r) => $r['tireman_id'], $rows),
            [$a, $b, $c]
        ));

        assert_same([$a, $b], $ids($repo->list(true)), 'active only, by name');
        assert_same([$c], $ids($repo->list(false)), 'inactive only');
        assert_same([$a, $b, $c], $ids($repo->list()), 'all: active first, then inactive');
        assert_same([$a, $b], $ids($repo->listActive()), 'listActive() never offers an inactive Tireman');

        foreach ($repo->listActive() as $row) {
            assert_same(1, $row['is_active']);
        }
        foreach ($repo->list(false) as $row) {
            assert_same(0, $row['is_active']);
        }
    });
});
