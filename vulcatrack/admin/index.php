<?php
/**
 * Minimal admin-protected page. It exists in Phase 3 only to demonstrate the
 * admin authorization guard. The admin dashboard is a later phase.
 */

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();

$pageTitle = 'Admin';
require __DIR__ . '/../src/Views/partials/top.php';
?>
<h1>Admin area</h1>
<p><?= e($admin['name']) ?> &mdash; signed in as administrator.</p>
<p class="muted">POS, inventory and rescue management arrive in later phases.</p>

<form method="post" action="<?= e(vulcatrack_url('/admin/logout.php')) ?>">
  <?= \VulcaTrack\Auth\Csrf::field() ?>
  <button type="submit">Log out</button>
</form>
<?php
require __DIR__ . '/../src/Views/partials/bottom.php';
