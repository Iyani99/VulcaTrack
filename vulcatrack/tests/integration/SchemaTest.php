<?php
/**
 * Integration tests -- the live database schema (Phase 2).
 *
 * Read-only (the only writes are constraint probes wrapped in rolled-back
 * transactions). Confirms the approved application tables exist with the
 * columns, nullability, unique keys and CHECK constraints the decision record
 * and schema.dbml require -- so Phase 5 starts from a known-good schema and any
 * accidental drift is caught immediately.
 */

namespace VulcaTrack\Tests;

test('the twelve approved application tables exist', function () {
    $pdo = test_pdo();
    assert_not_null($pdo, 'database must be reachable for the schema tests');

    $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
    sort($tables);
    $expected = ['admins', 'customers', 'inventory_adjustments', 'inventory_restocks', 'inventory_settings', 'items', 'product_promotions', 'sale_items', 'sales', 'service_requests', 'tiremen', 'vehicles'];
    assert_same($expected, $tables, 'the approved twelve-table schema must match');
});

test('Product Promotions schema preserves frozen sale lines and constrained history', function () {
    $pdo = test_pdo();
    $columns = schema_columns($pdo, 'product_promotions');
    assert_same(['promotion_id', 'item_id', 'sale_price', 'regular_price_snapshot',
        'starts_on', 'ends_on', 'unit_limit', 'units_consumed', 'note',
        'created_by_admin_id', 'created_at', 'cancelled_at', 'cancelled_by_admin_id'], array_keys($columns));
    $checks = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_promotions' AND CONSTRAINT_TYPE = 'CHECK'")
        ->fetchAll(\PDO::FETCH_COLUMN);
    foreach (['prices', 'dates', 'units', 'cancellation'] as $check) {
        assert_true(in_array('chk_product_promotions_' . $check, $checks, true));
    }
    assert_same('YES', $columns['unit_limit']['IS_NULLABLE']);
    assert_same('NO', $columns['starts_on']['IS_NULLABLE'], 'an omitted start is stored as the effective local date');
    assert_same('YES', $columns['ends_on']['IS_NULLABLE'], 'NULL means no automatic end date');
    assert_same('NO', $columns['units_consumed']['IS_NULLABLE']);
    $saleColumns = schema_columns($pdo, 'sale_items');
    assert_same('YES', $saleColumns['promotion_id']['IS_NULLABLE'], 'old and regular lines keep a NULL link');
    $fk = $pdo->query("SELECT COLUMN_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_items'
          AND CONSTRAINT_NAME = 'fk_sale_items_promotion_item' ORDER BY ORDINAL_POSITION")->fetchAll();
    assert_same(['promotion_id', 'item_id'], array_column($fk, 'COLUMN_NAME'));
    assert_same(['promotion_id', 'item_id'], array_column($fk, 'REFERENCED_COLUMN_NAME'));
});

test('Product override and adjustment history have constrained, attributed schema', function () {
    $pdo = test_pdo();
    $itemCols = schema_columns($pdo, 'items');
    assert_same('YES', $itemCols['slow_moving_days_override']['IS_NULLABLE']);
    assert_contains('slow_moving_days_override', schema_check_clause($pdo, 'items', 'slow_moving_override'));
    $cols = schema_columns($pdo, 'inventory_adjustments');
    assert_same([
        'adjustment_id', 'item_id', 'direction', 'quantity', 'reason', 'note',
        'stock_before', 'stock_after', 'admin_id', 'adjusted_at',
    ], array_keys($cols));
    foreach (['item_id', 'direction', 'quantity', 'reason', 'stock_before', 'stock_after', 'admin_id', 'adjusted_at'] as $name) {
        assert_same('NO', $cols[$name]['IS_NULLABLE']);
    }
    assert_same('YES', $cols['note']['IS_NULLABLE']);
    foreach (['direction', 'quantity', 'reason', 'note', 'movement'] as $check) {
        assert_true(schema_check_clause($pdo, 'inventory_adjustments', $check) !== '');
    }
    $fk = $pdo->query(
        "SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_adjustments'
           AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY REFERENCED_TABLE_NAME"
    )->fetchAll(\PDO::FETCH_COLUMN);
    assert_same(['admins', 'items'], $fk);
});

test('inventory_settings has one seeded threshold and constrained Admin attribution', function () {
    $pdo = test_pdo();
    $cols = schema_columns($pdo, 'inventory_settings');
    assert_same(['setting_id', 'slow_moving_days', 'updated_by_admin_id', 'updated_at'], array_keys($cols));
    assert_same('NO', $cols['slow_moving_days']['IS_NULLABLE']);
    assert_same('YES', $cols['updated_by_admin_id']['IS_NULLABLE']);
    assert_contains('slow_moving_days', schema_check_clause($pdo, 'inventory_settings', 'days'));
    assert_contains('setting_id', schema_check_clause($pdo, 'inventory_settings', 'setting_id'));
    $rows = $pdo->query('SELECT setting_id, slow_moving_days, updated_by_admin_id FROM inventory_settings')->fetchAll();
    assert_count(1, $rows);
    assert_same(1, (int) $rows[0]['setting_id']);
    assert_same(60, (int) $rows[0]['slow_moving_days']);
    assert_null($rows[0]['updated_by_admin_id']);
    $fk = $pdo->query(
        "SELECT k.REFERENCED_TABLE_NAME, r.DELETE_RULE, r.UPDATE_RULE
           FROM information_schema.KEY_COLUMN_USAGE k
           JOIN information_schema.REFERENTIAL_CONSTRAINTS r
             ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            AND r.TABLE_NAME = k.TABLE_NAME
          WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'inventory_settings'
            AND k.COLUMN_NAME = 'updated_by_admin_id'"
    )->fetch();
    assert_same('admins', $fk['REFERENCED_TABLE_NAME']);
    assert_same('RESTRICT', $fk['DELETE_RULE']);
    assert_same('RESTRICT', $fk['UPDATE_RULE']);
    TestDb::rollback($pdo, function () use ($pdo) {
        foreach ([0, 366] as $invalid) {
            assert_throws(fn () => $pdo->prepare('UPDATE inventory_settings SET slow_moving_days = ? WHERE setting_id = 1')
                ->execute([$invalid]), \PDOException::class);
        }
        assert_throws(fn () => $pdo->exec('INSERT INTO inventory_settings (setting_id, slow_moving_days) VALUES (2, 60)'), \PDOException::class);
        assert_throws(fn () => $pdo->exec('UPDATE inventory_settings SET updated_by_admin_id = 2147483647 WHERE setting_id = 1'), \PDOException::class);
    });
});

test('inventory_restocks has receipt fields, positive quantity, and restricted parent FKs', function () {
    $pdo = test_pdo();
    $cols = schema_columns($pdo, 'inventory_restocks');
    assert_same(['restock_id', 'item_id', 'quantity', 'stock_before', 'stock_after', 'supplier_name', 'note', 'admin_id', 'restocked_at'], array_keys($cols));
    foreach (['item_id', 'quantity', 'stock_before', 'stock_after', 'admin_id', 'restocked_at'] as $name) {
        assert_same('NO', $cols[$name]['IS_NULLABLE']);
    }
    assert_same('YES', $cols['supplier_name']['IS_NULLABLE']);
    assert_same('YES', $cols['note']['IS_NULLABLE']);
    assert_contains('quantity', schema_check_clause($pdo, 'inventory_restocks', 'quantity'));
    $movementCheck = schema_check_clause($pdo, 'inventory_restocks', 'movement');
    assert_contains('stock_before', $movementCheck);
    assert_contains('stock_after', $movementCheck);
    $fk = $pdo->query(
        "SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_restocks'
           AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY REFERENCED_TABLE_NAME"
    )->fetchAll(\PDO::FETCH_COLUMN);
    assert_same(['admins', 'items'], $fk);
});

test('customers has the mandatory-contact-number shape and a unique email', function () {
    $pdo = test_pdo();
    $cols = schema_columns($pdo, 'customers');
    assert_same('NO', $cols['contact_number']['IS_NULLABLE'], 'contact_number is mandatory (Decision 2)');
    assert_same('NO', $cols['email']['IS_NULLABLE']);
    assert_true(schema_has_unique_index($pdo, 'customers', 'email'), 'customers.email must be UNIQUE');
});

test('customers.avatar_filename is an optional short file-name column (Decision 77)', function () {
    $pdo = test_pdo();
    $cols = schema_columns($pdo, 'customers');
    assert_true(isset($cols['avatar_filename']), 'customers.avatar_filename must exist (run the 2026-09-29 migration)');
    assert_same('YES', $cols['avatar_filename']['IS_NULLABLE'], 'no picture = NULL; existing customers stay valid');
    $stmt = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'avatar_filename'"
    );
    $stmt->execute();
    assert_same('varchar(64)', $stmt->fetchColumn(), 'room for <customer_id>_<32 hex>.<ext>, never a path');
    assert_null($cols['avatar_filename']['COLUMN_DEFAULT'] === 'NULL' ? null : $cols['avatar_filename']['COLUMN_DEFAULT'], 'defaults to NULL');
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

test('service_requests carries optional one-time feedback with a named 1..5 CHECK (Decision 78)', function () {
    $pdo = test_pdo();
    $cols = schema_columns($pdo, 'service_requests');
    $types = [];
    $stmt = $pdo->query(
        "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_requests' AND COLUMN_NAME LIKE 'feedback%'"
    );
    foreach ($stmt->fetchAll() as $r) {
        $types[$r['COLUMN_NAME']] = $r['COLUMN_TYPE'];
    }
    foreach (['feedback_rating' => 'tinyint(3) unsigned', 'feedback_comment' => 'varchar(500)', 'feedback_submitted_at' => 'datetime'] as $col => $type) {
        assert_true(isset($cols[$col]), "service_requests.{$col} must exist (run the 2026-09-29 feedback migration)");
        assert_same('YES', $cols[$col]['IS_NULLABLE'], "{$col} is NULL until feedback is given");
        assert_same($type, $types[$col]);
    }

    $s = $pdo->query(
        "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'service_requests'
           AND CONSTRAINT_NAME = 'chk_service_requests_feedback_rating'"
    );
    $clause = (string) $s->fetchColumn();
    assert_contains('between 1 and 5', strtolower($clause), 'the named CHECK limits the rating to 1..5');

    // and MariaDB enforces it
    TestDb::rollback($pdo, function () use ($pdo) {
        $pdo->prepare('INSERT INTO customers (full_name, email, contact_number, password_hash) VALUES (?,?,?,?)')
            ->execute(['Schema Feedback', TestDb::email('schema-fb'), '09170000000', 'x']);
        $cid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO vehicles (customer_id, plate_number) VALUES (?,?)')->execute([$cid, 'SCH-FB']);
        $vid = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO service_requests (customer_id, vehicle_id, problem_description, status) VALUES (?,?,'p','completed')")->execute([$cid, $vid]);
        $rid = (int) $pdo->lastInsertId();
        foreach ([0, 6] as $bad) {
            assert_throws(function () use ($pdo, $rid, $bad) {
                $pdo->prepare('UPDATE service_requests SET feedback_rating = ? WHERE request_id = ?')->execute([$bad, $rid]);
            }, \PDOException::class, null, "rating {$bad} is rejected by the database");
        }
    });
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
