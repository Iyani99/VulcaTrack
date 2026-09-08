<?php
/**
 * Integration tests -- the database SESSION VulcaTrack gives every connection.
 *
 * `includes/db.php` puts each PDO session into strict mode
 * (`STRICT_TRANS_TABLES`) so an over-long string or an out-of-range number is
 * rejected, never silently truncated / clamped. The XAMPP server's *global*
 * sql_mode is left non-strict and untouched -- this is session-scoped only, so
 * the behaviour is the same on every machine, in tests, and on a future host.
 *
 * This originated from the Phase 5 sales-foundation audit, where an over-range
 * `sale_items.quantity` was silently clamped instead of rejected.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Support\Money;

test('every vulcatrack_db() session runs in strict mode with the other modes preserved', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'the database must be reachable');

    $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    assert_contains('STRICT_TRANS_TABLES', $mode, 'VulcaTrack sessions must be strict');
    assert_contains('NO_ENGINE_SUBSTITUTION', $mode, 'existing modes are preserved, not replaced');

    // The server-scoped default is deliberately NOT changed by us.
    $global = (string) $pdo->query('SELECT @@GLOBAL.sql_mode')->fetchColumn();
    assert_not_contains('STRICT_TRANS_TABLES', $global, 'we do not touch the global / my.ini sql_mode');
});

test('the session sql_mode expression is correct for every inherited starting state', function () {
    $pdo = test_pdo();
    assert_not_null($pdo);

    // The exact statement includes/db.php runs on each new connection.
    // Keep this string in sync with that file.
    $apply = "SET SESSION sql_mode = IF("
        . "FIND_IN_SET('STRICT_TRANS_TABLES', @@SESSION.sql_mode), "
        . "@@SESSION.sql_mode, "
        . "CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_TRANS_TABLES'))";

    $strictCount = static function (string $mode): int {
        return count(array_filter(explode(',', $mode), fn ($m) => $m === 'STRICT_TRANS_TABLES'));
    };

    $original = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    try {
        // 1. inherited mode is a non-strict list -> strict is added, list kept, once.
        $pdo->exec("SET SESSION sql_mode = 'NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec($apply);
        $pdo->exec($apply); // twice: must be stable
        $r = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        assert_same(1, $strictCount($r), 'strict appears exactly once');
        assert_contains('NO_ZERO_DATE', $r, 'inherited modes are preserved');
        assert_contains('NO_ENGINE_SUBSTITUTION', $r);

        // 2. inherited mode already contains strict -> unchanged, no duplicate.
        $pdo->exec("SET SESSION sql_mode = 'NO_ZERO_DATE,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec($apply);
        $r = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        assert_same(1, $strictCount($r), 'no second STRICT_TRANS_TABLES is appended');
        assert_contains('NO_ZERO_DATE', $r);

        // 3. inherited mode is empty -> just strict, no leading/trailing comma.
        $pdo->exec("SET SESSION sql_mode = ''");
        $pdo->exec($apply);
        $r = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        assert_same('STRICT_TRANS_TABLES', $r, 'clean result from an empty inherited mode');
    } finally {
        $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($original));
    }
});

test('strict mode rejects an over-length string instead of silently truncating it', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $repo = new ItemRepository($pdo);
        // item_name is VARCHAR(150); 200 chars would truncate silently under a
        // non-strict session. Application validation caps this well before the
        // DB, so this is the DB acting as the last line of defence.
        assert_throws(
            fn () => $repo->create(str_repeat('x', 200), 'service', null, Money::toCentavos('1.00'), null, null),
            \PDOException::class,
            '1406'
        );

        $long = $pdo->query("SELECT COUNT(*) FROM items WHERE item_name LIKE 'xxxxx%'")->fetchColumn();
        assert_same(0, (int) $long, 'nothing was written -- not a truncated row');
    });
});

test('strict mode rejects an out-of-range integer instead of clamping it', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        // stock_quantity is a signed INT; 3,000,000,000 exceeds it. Under a
        // non-strict session MariaDB stored 2,147,483,647 with only a warning.
        assert_throws(
            function () use ($pdo) {
                $pdo->prepare("INSERT INTO items (item_name, item_type, price, stock_quantity, is_active)
                               VALUES ('probe strict int', 'product', '1.00', :q, 1)")
                    ->execute([':q' => 3000000000]);
            },
            \PDOException::class,
            '1264'
        );
    });
});

test('a legitimate high-precision OTG coordinate still stores (fractional rounding is a warning, not an error)', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        // latitude / longitude are DECIMAL(10,7); the browser sends ~15 dp.
        // Strict mode rounds the fraction to scale WITH A WARNING ONLY, so the
        // rescue-submission path is unaffected by the strict session.
        $pdo->exec(
            "INSERT INTO customers (full_name, email, contact_number, password_hash)
             VALUES ('Coord Probe', 'coordprobe" . bin2hex(random_bytes(4)) . "@vulcatrack.test', '0917', 'h')"
        );
        $cid = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO vehicles (customer_id, plate_number) VALUES ({$cid}, 'COORD-1')");
        $vid = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            "INSERT INTO service_requests
                 (customer_id, vehicle_id, problem_description, latitude, longitude, eta_minutes, status)
             VALUES (:c, :v, 'probe', :lat, :lng, 10, 'pending')"
        );
        $stmt->execute([
            ':c' => $cid, ':v' => $vid,
            ':lat' => 14.946654430279454, ':lng' => 120.89290174619997,
        ]);

        $row = $pdo->query("SELECT latitude, longitude FROM service_requests WHERE customer_id = {$cid}")->fetch();
        assert_same('14.9466544', $row['latitude'], 'stored rounded to the column scale, no error');
        assert_same('120.8929017', $row['longitude']);
    });
});
