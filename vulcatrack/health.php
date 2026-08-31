<?php
/**
 * VulcaTrack -- Phase 1 environment & connectivity check.
 *
 * Verifies the PHP -> Apache -> MySQL/MariaDB chain. This is a scaffold
 * diagnostic, not an application feature. Remove or restrict before any
 * production deployment.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/db.php';

header('Content-Type: text/html; charset=utf-8');

$checks = [];

$checks[] = [
    'PHP version >= 8.0',
    version_compare(PHP_VERSION, '8.0.0', '>='),
    PHP_VERSION,
];

foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'curl', 'fileinfo'] as $ext) {
    $loaded = extension_loaded($ext);
    $checks[] = ["Extension: {$ext}", $loaded, $loaded ? 'loaded' : 'missing'];
}

$serverOk = false;
$serverInfo = '';
try {
    $serverInfo = vulcatrack_db(false)->query('SELECT VERSION()')->fetchColumn();
    $serverOk = true;
} catch (Throwable $e) {
    $serverInfo = $e->getMessage();
}
$checks[] = ['MySQL / MariaDB server reachable', $serverOk, $serverInfo];

$dbName = $GLOBALS['vulcatrack_config']['db']['name'];
$dbOk = false;
$dbInfo = '';
try {
    $dbInfo = vulcatrack_db(true)->query('SELECT DATABASE()')->fetchColumn();
    $dbOk = true;
} catch (Throwable $e) {
    $dbInfo = $e->getMessage();
}
$checks[] = ["Database \"{$dbName}\" connects", $dbOk, $dbInfo !== '' ? $dbInfo : '(no database selected)'];

$allOk = array_reduce($checks, static function ($carry, $row) {
    return $carry && $row[1];
}, true);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VulcaTrack -- Environment Check</title>
<style>
  body{font-family:system-ui,"Segoe UI",Arial,sans-serif;margin:2rem auto;max-width:48rem;padding:0 1rem;color:#1a1a1a}
  table{border-collapse:collapse;width:100%;margin-top:1rem}
  th,td{border:1px solid #ccc;padding:.5rem .6rem;text-align:left;vertical-align:top}
  th{background:#f5f5f5}
  .ok{color:#0a7f2e;font-weight:600}
  .fail{color:#c0272d;font-weight:600}
  .banner{padding:.8rem 1rem;border-radius:6px;margin:1rem 0;font-weight:600}
  .banner.ok{background:#e6f5ec}
  .banner.fail{background:#fbe7e7}
  code{white-space:pre-wrap;word-break:break-word}
  .muted{color:#666}
</style>
</head>
<body>
  <h1>VulcaTrack &mdash; Environment Check</h1>
  <p class="muted">Phase 1 foundation test: PHP &rarr; Apache &rarr; MySQL / MariaDB.</p>

  <div class="banner <?= $allOk ? 'ok' : 'fail' ?>">
    <?= $allOk ? 'All checks passed.' : 'One or more checks failed &mdash; see the table.' ?>
  </div>

  <table>
    <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $row): ?>
      <tr>
        <td><?= htmlspecialchars($row[0]) ?></td>
        <td class="<?= $row[1] ? 'ok' : 'fail' ?>"><?= $row[1] ? 'PASS' : 'FAIL' ?></td>
        <td><code><?= htmlspecialchars((string) $row[2]) ?></code></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <p class="muted">Generated <?= date('c') ?></p>
</body>
</html>
