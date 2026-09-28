<?php
/**
 * Integration tests -- the live database schema (Phase 2).
 *
 * Read-only (the only writes are constraint probes wrapped in rolled-back
 * transactions). Confirms the eight locked application tables exist with the
 * columns, nullability, unique keys and CHECK constraints the decision record
 * and schema.dbml require -- so Phase 5 starts from a known-good schema and any
 * accidental drift is caught immediately.
 */

namespace VulcaTrack\Tests;

test('exactly the eight locked application tables exist', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'database must be reachable for the schema tests');

    $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
    sort($tables);
    $expected = ['admins', 'customers', 'items', 'sale_items', 'sales', 'service_requests', 'tiremen', 'vehicles'];
    assert_same($expected, $tables, 'the 8-table schema must match exactly -- no 9th table, none missing');
});

test('customers has the mandatory-contact-number shape and a unique email', function () {
    $pdo = test_pdo();
    $cols = schema_columns($pdo, 'customers');
    assert_same('NO', $cols['contact_number']['IS_NULLABLE'], 'contact_number is mandatory (Decision 2)');
    assert_same('NO', $cols['email']['IS_NULLABLE']);
    assert_true(schema_has_unique_index($pdo, 'customers', 'email'), 'customers.email must be UNIQUE');
});

test('admins has a unique email independent of customers', function () {
    $pdo = test_pdo();
    assert_true(schema_has_unique_index($pdo, 'admins', 'email'), 'admins.email must be UNIQUE (Decision 42)');
});

test('sales supports walk-in (customer_id nullable) and requires a recording admin', function () {
    $pdo = test_pdo();
    $cols = schema_columns($pdo, 'sales');
    assert_same('YES', $cols['customer_id']['IS_NULLABLE'], 'walk-in sales need a nullable customer_id (Decision 14)');
    assert_same('NO', $cols['admin_id']['IS_NULLABLE'], 'every sale has exactly one recording admin');
    assert_same('NO', $cols['sale_date']['IS_NULLABLE']);
    assert_null($cols['sale_date']['COLUMN_DEFAULT'], 'sale_date has no default -- the app supplies it (Decision 35)');
    assert_same('NO', $cols['total_amount']['IS_NULLABLE']);
});

test('sales.service_request_id is a nullable, UNIQUE, RESTRICT foreign key to service_requests (Phase 7.3d-a)', function () {
    $pdo = test_pdo();
    $cols = schema_columns($pdo, 'sales');
    assert_true(isset($cols['service_request_id']), 'sales.service_request_id must exist (run database/migrations/ on an older database)');
    assert_same('YES', $cols['service_request_id']['IS_NULLABLE'], 'NULL = an ordinary POS sale not linked to a Rescue');

    // Same column type as the key it references (INT, signed).
    $type = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $type->execute(['sales', 'service_request_id']);
    $linkType = (string) $type->fetchColumn();
    $type->execute(['service_requests', 'request_id']);
    assert_same('int', $cols['service_request_id']['DATA_TYPE']);
    assert_same((string) $type->fetchColumn(), $linkType, 'must match service_requests.request_id exactly');

    // A single-column UNIQUE key: zero-or-one sale per request.
    $idx = $pdo->prepare(
        "SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND INDEX_NAME = 'uq_sales_service_request'"
    );
    $idx->execute();
    $rows = $idx->fetchAll();
    assert_count(1, $rows, 'uq_sales_service_request must cover exactly one column');
    assert_same('service_request_id', $rows[0]['COLUMN_NAME']);
    assert_same(0, (int) $rows[0]['NON_UNIQUE'], 'the key must be UNIQUE');

    // Exactly one FK on the column, to service_requests.request_id, RESTRICT both ways.
    $fk = $pdo->prepare(
        "SELECT k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          AND r.TABLE_NAME = k.TABLE_NAME
         WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'sales'
           AND k.COLUMN_NAME = 'service_request_id' AND k.REFERENCED_TABLE_NAME IS NOT NULL"
    );
    $fk->execute();
    $fks = $fk->fetchAll();
    assert_count(1, $fks, 'sales.service_request_id needs exactly one foreign key');
    assert_same('fk_sales_service_request', $fks[0]['CONSTRAINT_NAME']);
    assert_same('service_requests', $fks[0]['REFERENCED_TABLE_NAME']);
    assert_same('request_id', $fks[0]['REFERENCED_COLUMN_NAME']);
    assert_same('RESTRICT', $fks[0]['DELETE_RULE'], 'never cascade-delete a sale or its link');
    assert_same('RESTRICT', $fks[0]['UPDATE_RULE']);
});

test('the database enforces the sale -> Rescue link: many unlinked sales, one sale per request, RESTRICT', function () {
    $pdo = test_pdo();
    TestDb::rollback($pdo, function () use ($pdo) {
        $pdo->prepare("INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES ('Schema Probe', ?, '09170000000', 'x')")
            ->execute([TestDb::email('schema-probe')]);
        $customerId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO admins (full_name, email, password_hash) VALUES ('Schema Probe Admin', ?, 'x')")
            ->execute([TestDb::email('schema-probe-admin')]);
        $adminId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO vehicles (customer_id, plate_number) VALUES (?, 'PROBE-1')")->execute([$customerId]);
        $vehicleId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO service_requests (customer_id, vehicle_id, problem_description, status) VALUES (?, ?, 'probe', 'accepted')")
            ->execute([$customerId, $vehicleId]);
        $requestId = (int) $pdo->lastInsertId();

        $sale = $pdo->prepare(
            "INSERT INTO sales (customer_id, service_request_id, admin_id, sale_date, total_amount)
             VALUES (?, ?, ?, '2001-01-01 10:00:00', '1.00')"
        );
        // Any number of unlinked (NULL) sales — every ordinary POS sale.
        $sale->execute([null, null, $adminId]);
        $sale->execute([$customerId, null, $adminId]);
        // One linked sale per request.
        $sale->execute([$customerId, $requestId, $adminId]);
        assert_throws(fn () => $sale->execute([$customerId, $requestId, $adminId]),
            \PDOException::class, 'uq_sales_service_request', 'a second sale for the same request must be refused');
        assert_throws(fn () => $sale->execute([$customerId, 2147483646, $adminId]),
            \PDOException::class, 'fk_sales_service_request', 'a link to a request that does not exist must be refused');

        // RESTRICT: a request with a sale can be neither deleted nor re-keyed.
        assert_throws(fn () => $pdo->prepare('DELETE FROM service_requests WHERE request_id = ?')->execute([$requestId]),
            \PDOException::class, 'fk_sales_service_request', 'ON DELETE RESTRICT');
        assert_throws(fn () => $pdo->prepare('UPDATE service_requests SET request_id = 2147483645 WHERE request_id = ?')->execute([$requestId]),
            \PDOException::class, 'fk_sales_service_request', 'ON UPDATE RESTRICT');
    });
});

test('sale_items carries a frozen unit_price and a subtotal, all NOT NULL', function () {
    $pdo = test_pdo();
    $cols = schema_columns($pdo, 'sale_items');
    foreach (['sale_id', 'item_id', 'quantity', 'unit_price', 'subtotal'] as $c) {
        assert_true(isset($cols[$c]), "sale_items.{$c} must exist");
        assert_same('NO', $cols[$c]['IS_NULLABLE'], "sale_items.{$c} is NOT NULL");
    }
});

test('items unifies products and services; the item_type CHECK is enforced', function () {
    $pdo = test_pdo();
    $cols = schema_columns($pdo, 'items');
    assert_same('NO', $cols['item_type']['IS_NULLABLE']);
    assert_same('YES', $cols['stock_quantity']['IS_NULLABLE'], 'stock is products-only, so nullable (Decision 16)');
    assert_same('YES', $cols['category']['IS_NULLABLE'], 'category stays a plain nullable field (no category table)');
    assert_true(schema_has_check($pdo, 'items', 'item_type'), 'items needs a CHECK constraint on item_type');

    // The CHECK really rejects an unknown type (no FK on this table, so the
    // CHECK is the only thing that can fail here).
    assert_throws(function () use ($pdo) {
        TestDb::rollback($pdo, function () use ($pdo) {
            $pdo->exec("INSERT INTO items (item_name, item_type, price) VALUES ('probe', 'widget', 1.00)");
        });
    }, \PDOException::class, null, "item_type = 'widget' must be rejected");
});

test('service_requests locks the four statuses and keeps admin/tireman FKs nullable', function () {
    $pdo = test_pdo();
    $cols = schema_columns($pdo, 'service_requests');
    assert_same('NO', $cols['customer_id']['IS_NULLABLE'], 'customer_id NOT NULL (Decisions 1/39)');
    assert_same('NO', $cols['vehicle_id']['IS_NULLABLE']);
    assert_same('YES', $cols['admin_id']['IS_NULLABLE']);
    assert_same('YES', $cols['tireman_id']['IS_NULLABLE'], 'tireman_id set only on/after accept (Decision 25)');
    assert_same('YES', $cols['eta_minutes']['IS_NULLABLE']);
    assert_true(schema_has_check($pdo, 'service_requests', 'status'), 'status needs a CHECK constraint');

    // Confirm the CHECK clause lists exactly the four locked values.
    $clause = schema_check_clause($pdo, 'service_requests', 'status');
    foreach (['pending', 'accepted', 'rejected', 'completed'] as $s) {
        assert_contains($s, $clause, "the status CHECK should permit '{$s}'");
    }
    foreach (['dispatched', 'on_the_way', 'cancelled', 'in_progress', 'arrived'] as $s) {
        assert_not_contains($s, $clause, "the status CHECK must not mention '{$s}'");
    }
});

test('tiremen is identity/contact only -- no login columns', function () {
    $pdo = test_pdo();
    $cols = schema_columns($pdo, 'tiremen');
    assert_same(
        ['tireman_id', 'name', 'contact_number', 'is_active', 'created_at', 'updated_at'],
        array_keys($cols),
        'tiremen must stay minimal (Decisions 22-26) -- no password/email/role column'
    );
});

test('no status-history / payment / receipt / shop_settings tables crept in', function () {
    $pdo = test_pdo();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
    foreach (['payments', 'payment_transactions', 'receipts', 'shop_settings', 'status_history', 'categories', 'suppliers'] as $forbidden) {
        assert_false(in_array($forbidden, $tables, true), "'{$forbidden}' must not exist (out of scope)");
    }
});

// --- helpers -------------------------------------------------------------------

function schema_columns(\PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE, ORDINAL_POSITION
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([$table]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['COLUMN_NAME']] = $row;
    }
    return $out;
}

function schema_has_unique_index(\PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function schema_check_clause(\PDO $pdo, string $table, string $needle): string
{
    try {
        $stmt = $pdo->prepare(
            'SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $clause) {
            if (stripos((string) $clause, $needle) !== false) {
                return (string) $clause;
            }
        }
    } catch (\PDOException) {
        // ignore -- fall through to SHOW CREATE TABLE
    }
    $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(\PDO::FETCH_NUM)[1] ?? '';
    if (preg_match('/CHECK\s*\(([^;]*?' . preg_quote($needle, '/') . '[^;]*?)\)\s*[,)]/i', $create, $m)) {
        return $m[1];
    }
    return '';
}

function schema_has_check(\PDO $pdo, string $table, string $needle): bool
{
    return schema_check_clause($pdo, $table, $needle) !== '';
}
