<?php
/**
 * Minimal customer-protected page. It exists in Phase 3 only to demonstrate the
 * customer authorization guard. The customer dashboard is a later phase.
 */

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';

$customer = require_customer();

$pageTitle = 'Account';
require __DIR__ . '/src/Views/partials/top.php';
?>
<h1>You are signed in</h1>
<p><?= e($customer['name']) ?> &mdash; customer account.</p>
<p class="muted">Profile, saved vehicles and bookings arrive in a later phase.</p>

<form method="post" action="<?= e(vulcatrack_url('/logout.php')) ?>">
  <?= \VulcaTrack\Auth\Csrf::field() ?>
  <button type="submit">Log out</button>
</form>
<?php
require __DIR__ . '/src/Views/partials/bottom.php';
