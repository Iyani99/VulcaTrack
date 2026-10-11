<?php
/**
 * VulcaTrack -- database connection factory (PDO, MySQL / MariaDB).
 *
 * Phase 1: this only establishes the PHP -> MySQL link. No queries, no models,
 * no schema. Those come in a later, approved phase.
 */

require_once __DIR__ . '/bootstrap.php';

/** Fail before connecting if test configuration names anything but the test DB. */
function vulcatrack_assert_test_database_name($name): void
{
    if ($name !== 'vulcatrack_test') {
        throw new RuntimeException('VulcaTrack tests require database vulcatrack_test.');
    }
}

/** Confirm the server actually selected the intended database before any write. */
function vulcatrack_assert_test_database_connection(PDO $pdo): void
{
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'vulcatrack_test') {
        throw new RuntimeException('VulcaTrack tests connected to an unexpected database; refusing to continue.');
    }
}

/**
 * Return a shared PDO connection.
 *
 * @param bool $withDatabase When false, connect to the server WITHOUT selecting
 *                           a database (used by the health check before the
 *                           schema / database exists).
 * @return PDO
 */
function vulcatrack_db($withDatabase = true)
{
    static $connections = [];
    $key = $withDatabase ? 'db' : 'server';

    if (isset($connections[$key])) {
        if (VULCATRACK_RUNNING_TESTS) {
            vulcatrack_assert_test_database_name($GLOBALS['vulcatrack_config']['db']['name'] ?? null);
            vulcatrack_assert_test_database_connection($connections[$key]);
        }
        return $connections[$key];
    }

    $cfg = $GLOBALS['vulcatrack_config']['db'];

    if (VULCATRACK_RUNNING_TESTS) {
        vulcatrack_assert_test_database_name($cfg['name'] ?? null);
        if (!$withDatabase) {
            throw new RuntimeException('Server-only database connections are forbidden during tests.');
        }
    }

    $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';charset=' . $cfg['charset'];
    if ($withDatabase) {
        $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port']
             . ';dbname=' . $cfg['name'] . ';charset=' . $cfg['charset'];
    }

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    if (VULCATRACK_RUNNING_TESTS) {
        vulcatrack_assert_test_database_connection($pdo);
    }

    // VulcaTrack runs its OWN database sessions in strict mode: an over-long
    // string or an out-of-range number is rejected with an error, never
    // silently truncated or clamped. This XAMPP server's global sql_mode is
    // non-strict; we do NOT touch that or my.ini -- this SET is session-scoped,
    // so the app behaves identically on any machine, in tests, and on a future
    // host. Every other inherited mode (NO_ZERO_DATE, NO_ENGINE_SUBSTITUTION, ...)
    // is kept. The expression is explicit about all three starting states:
    //   - strict already present  -> leave the mode untouched (no duplicate)
    //   - strict absent, list set -> prepend it, CONCAT_WS keeps the separator right
    //   - inherited mode empty    -> NULLIF drops the empty side, so no stray comma
    $pdo->exec(
        "SET SESSION sql_mode = IF("
        . "FIND_IN_SET('STRICT_TRANS_TABLES', @@SESSION.sql_mode), "
        . "@@SESSION.sql_mode, "
        . "CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_TRANS_TABLES'))"
    );

    $connections[$key] = $pdo;
    return $pdo;
}
