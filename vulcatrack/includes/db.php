<?php
/**
 * VulcaTrack -- database connection factory (PDO, MySQL / MariaDB).
 *
 * Phase 1: this only establishes the PHP -> MySQL link. No queries, no models,
 * no schema. Those come in a later, approved phase.
 */

require_once __DIR__ . '/bootstrap.php';

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
        return $connections[$key];
    }

    $cfg = $GLOBALS['vulcatrack_config']['db'];

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

    $connections[$key] = $pdo;
    return $pdo;
}
