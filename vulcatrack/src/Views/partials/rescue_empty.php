<?php
/**
 * Empty state for the admin Rescue list (admin/rescue.php).
 *
 * Expects: string $status  — the current filter: 'pending' | 'accepted' |
 *          'rejected' | 'completed' | 'all' (already whitelisted by the page).
 *
 * An empty status view links to one other status and to All, so an admin is
 * never stuck on an empty list (e.g. Pending after accepting the only request).
 * The All view has nowhere else to go, so it shows only the message.
 * Kept as a partial so the markup can be rendered in tests without a database.
 */
?>
<p class="muted"><?= $status === 'all' ? 'No rescue requests yet.' : 'No ' . e($status) . ' requests.' ?></p>
<?php if ($status !== 'all'): ?>
  <?php $other = $status === 'pending' ? 'accepted' : 'pending'; ?>
  <p>
    <a href="<?= e(vulcatrack_url('/admin/rescue.php?status=' . $other)) ?>">View <?= e($other) ?></a>
    &middot;
    <a href="<?= e(vulcatrack_url('/admin/rescue.php?status=all')) ?>">View all</a>
  </p>
<?php endif; ?>
