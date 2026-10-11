<?php
/**
 * Local test configuration template. Copy to config.test.php (git-ignored).
 * Reuses local connection settings without copying credentials into Git.
 * The test runner and database factory refuse any name except vulcatrack_test.
 */

$config = require __DIR__ . '/config.php';
$config['db']['name'] = 'vulcatrack_test';

return $config;
