<?php
/**
 * VulcaTrack test harness -- shared bootstrap.
 *
 * Loads the assertion library and the application (config, autoloader, helpers).
 * Included once by tests/run.php before any test file.
 *
 * The application bootstrap only starts a PHP session for non-CLI SAPIs, so the
 * class-level tests run with $_SESSION as a plain array -- enough to exercise
 * Auth / Csrf without a real session backend. End-to-end session behaviour is
 * covered separately by the HTTP tests (tests/http/), which drive a real
 * `php -S` process.
 */

namespace VulcaTrack\Tests;

error_reporting(E_ALL);

define('VULCATRACK_TEST_ROOT', __DIR__);
define('VULCATRACK_APP_ROOT', dirname(__DIR__));

require __DIR__ . '/lib/Assert.php';
require __DIR__ . '/lib/TestDb.php';
require __DIR__ . '/lib/HttpClient.php';

// The application's own bootstrap: config, PSR-4-ish autoloader, e()/vulcatrack_url().
require VULCATRACK_APP_ROOT . '/includes/bootstrap.php';
require VULCATRACK_APP_ROOT . '/includes/db.php';

if (!isset($_SESSION) || !is_array($_SESSION)) {
    $_SESSION = [];
}

/** Absolute path to a file inside the application. */
function app_path(string $relative): string
{
    return VULCATRACK_APP_ROOT . '/' . ltrim($relative, '/');
}

/** The shared PDO handle, or null when the database is unreachable. */
function test_pdo(): ?\PDO
{
    static $pdo = null;
    static $tried = false;
    if (!$tried) {
        $tried = true;
        try {
            $pdo = vulcatrack_db(true);
        } catch (\Throwable $e) {
            fwrite(STDERR, "  [db] connection failed: " . $e->getMessage() . "\n");
            $pdo = null;
        }
    }
    return $pdo;
}
