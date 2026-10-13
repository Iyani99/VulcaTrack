<?php
/** Explicit, destructive reset of vulcatrack_test from the canonical schema. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--check')) {
    fwrite(STDERR, "Usage: php tests/setup-db.php [--check]\n");
    exit(2);
}

putenv('VULCATRACK_TEST_MODE=1');
require __DIR__ . '/../includes/db.php';

$cfg = $GLOBALS['vulcatrack_config']['db'];
vulcatrack_assert_test_database_name($cfg['name'] ?? null);

// The first two canonical statements explicitly select the real database.
// Validate them, then discard them. Only the expected unqualified table DDL
// is allowed through to the already-selected test database connection.
$source = file_get_contents(__DIR__ . '/../database/schema.sql');
if ($source === false) {
    throw new RuntimeException('Cannot read canonical database/schema.sql.');
}
$withoutComments = preg_replace('/^[ \t]*--[^\r\n]*$/m', '', $source);
if ($withoutComments === null) {
    throw new RuntimeException('Cannot parse canonical schema comments.');
}
$statements = array_values(array_filter(
    array_map('trim', explode(';', $withoutComments)),
    static fn (string $sql): bool => $sql !== ''
));
$normalise = static fn (string $sql): string => (string) preg_replace('/\s+/', ' ', $sql);
if (count($statements) !== 27
    || $normalise($statements[0]) !== 'CREATE DATABASE IF NOT EXISTS `vulcatrack` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    || $normalise($statements[1]) !== 'USE `vulcatrack`') {
    throw new RuntimeException('Canonical schema preamble changed; refusing test reset.');
}

$drops = ['sale_items', 'sales', 'service_requests', 'vehicles', 'inventory_adjustments', 'inventory_restocks', 'product_promotions', 'items', 'tiremen', 'inventory_settings', 'admins', 'customers'];
$creates = ['customers', 'admins', 'inventory_settings', 'tiremen', 'items', 'product_promotions', 'inventory_restocks', 'inventory_adjustments', 'vehicles', 'service_requests', 'sales', 'sale_items'];
$ddl = array_slice($statements, 2, count($drops) + count($creates));
foreach ($ddl as $index => $sql) {
    if (preg_match('/\bvulcatrack\b|\b(?:CREATE|DROP|ALTER)\s+DATABASE\b|\bUSE\s+`?/i', $sql)) {
        throw new RuntimeException('Database selection or database-qualified DDL found; refusing test reset.');
    }
    if ($index < count($drops)) {
        if (!preg_match('/^DROP TABLE IF EXISTS `([a-z_]+)`$/', $sql, $match)
            || $match[1] !== $drops[$index]) {
            throw new RuntimeException('Unexpected table DROP in canonical schema; refusing test reset.');
        }
    } elseif (!preg_match('/^CREATE TABLE `([a-z_]+)`\s*\(/', $sql, $match)
        || $match[1] !== $creates[$index - count($drops)]) {
        throw new RuntimeException('Unexpected table CREATE in canonical schema; refusing test reset.');
    }
}
$seed = $statements[count($statements) - 1];
if ($normalise($seed) !== 'INSERT INTO `inventory_settings` (`setting_id`, `slow_moving_days`) VALUES (1, 60)') {
    throw new RuntimeException('Unexpected inventory setting seed; refusing test reset.');
}

if (($argv[1] ?? null) === '--check') {
    echo "Safe target: vulcatrack_test; canonical schema: 12 drops, 12 creates, 1 settings seed. No database changed.\n";
    exit(0);
}

$serverDsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';charset=' . $cfg['charset'];
$server = new PDO($serverDsn, $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
if ($server->query('SELECT DATABASE()')->fetchColumn() !== null) {
    throw new RuntimeException('Setup server connection unexpectedly selected a database.');
}
$server->exec('CREATE DATABASE IF NOT EXISTS `vulcatrack_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$pdo = vulcatrack_db(true); // checks SELECT DATABASE() before returning
$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$unexpected = array_diff($existing, $creates);
if ($unexpected !== []) {
    throw new RuntimeException('Test database has unexpected tables; refusing partial reset.');
}
foreach ($ddl as $sql) {
    vulcatrack_assert_test_database_connection($pdo);
    $pdo->exec($sql);
}
vulcatrack_assert_test_database_connection($pdo);
$pdo->exec($seed);
echo "Rebuilt vulcatrack_test from the canonical schema (12 tables).\n";
