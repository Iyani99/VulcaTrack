<?php
/**
 * Admin — Rescue (OTG) requests list (Phase 6, Chunk 6.2). READ-ONLY.
 *
 * Every customer's On-the-Go requests, filtered by status through a GET
 * whitelist (default: pending). The status dropdown submits itself on change
 * (the Filter button stays as the no-JavaScript fallback); an empty view links
 * to another status and to All. Each row links to rescue-view.php. There are
 * no forms that change anything here — the status actions (accept / reassign /
 * reject / complete) live on rescue-view.php (Chunk 6.3).
 */

use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Support\OtgStatus;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();

// Status filter: one of the four statuses or 'all'; anything else means 'pending'.
$statusParam = (string) ($_GET['status'] ?? '');
$status = ($statusParam === 'all' || OtgStatus::isValid($statusParam)) ? $statusParam : 'pending';

$requests = (new ServiceRequestRepository(vulcatrack_db()))
    ->listForAdmin($status === 'all' ? null : $status);

$pageTitle = 'Rescue requests';
$navActive = 'rescue';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <h1>Rescue requests</h1>
</div>
<p class="muted">On-the-Go service requests from customers. This view is read-only.</p>

<form class="filterbar" method="get" action="<?= e(vulcatrack_url('/admin/rescue.php')) ?>">
  <label>Status
    <select name="status" onchange="this.form.submit()">
      <?php foreach (array_merge(OtgStatus::VALUES, ['all']) as $option): ?>
        <option value="<?= e($option) ?>"<?= $status === $option ? ' selected' : '' ?>><?= e($option === 'all' ? 'All' : OtgStatus::adminLabel($option)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button type="submit">Filter</button>
</form>

<?php $n = count($requests); ?>
<p class="muted"><?= $n ?> <?= $n === 1 ? 'request' : 'requests' ?>.</p>

<?php if (!$requests): ?>
  <?php require __DIR__ . '/../src/Views/partials/rescue_empty.php'; ?>
<?php else: ?>
  <div class="table-scroll">
  <table class="datatable">
    <thead>
      <tr><th>#</th><th>Requested</th><th>Customer</th><th>Vehicle</th><th>Status</th><th>Tireman</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($requests as $r): ?>
      <tr>
        <td><?= (int) $r['request_id'] ?></td>
        <td class="muted"><?= e($r['requested_at']) ?></td>
        <td><?= e($r['customer_name']) ?></td>
        <td><?= e($r['plate_number']) ?></td>
        <td>
          <span class="badge <?= e(OtgStatus::badgeClass($r['status'])) ?>"><?= e(OtgStatus::adminLabel($r['status'])) ?></span>
        </td>
        <td><?= $r['tireman_name'] !== null ? e($r['tireman_name']) : '<span class="muted">—</span>' ?></td>
        <td class="rowactions">
          <a href="<?= e(vulcatrack_url('/admin/rescue-view.php?id=' . (int) $r['request_id'])) ?>">View</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
