<?php
/**
 * Admin — Rescue (OTG) requests list (Phase 6, Chunk 6.2). READ-ONLY.
 *
 * Every customer's On-the-Go requests, filtered by status through a GET
 * whitelist (default: pending). Since Phase 7.3b the filter is a row of status
 * tabs — plain links carrying the same ?status= values; JavaScript only
 * moves the decorative underline. An empty view links to another status and to All. Each row
 * links to rescue-view.php. There are no forms that change anything here — the
 * status actions (accept / reassign / reject / complete) live on
 * rescue-view.php (Chunk 6.3).
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
  <div>
    <h1>Rescue requests</h1>
    <p class="pagehead__meta">On-the-Go service requests from customers. Open a request to accept, assign, complete or reject it.</p>
  </div>
</div>

<?php // Status tabs: the four real statuses + All, in workflow order. aria-current="true"
      // (not "page") marks the chosen filter — "page" belongs to the sidebar nav. ?>
<nav class="tabs" aria-label="Filter by status">
  <?php foreach (['pending', 'accepted', 'completed', 'rejected', 'all'] as $option): ?>
    <a class="tab<?= $status === $option ? ' is-active' : '' ?>" href="<?= e(vulcatrack_url('/admin/rescue.php?status=' . $option)) ?>"<?= $status === $option ? ' aria-current="true"' : '' ?>><?= e($option === 'all' ? 'All' : OtgStatus::adminLabel($option)) ?></a>
  <?php endforeach; ?>
  <span class="tabs__indicator" aria-hidden="true"></span>
</nav>

<?php $n = count($requests); ?>

<?php if (!$requests): ?>
  <?php require __DIR__ . '/../src/Views/partials/rescue_empty.php'; ?>
<?php else: ?>
  <div class="tablepanel">
  <div class="table-scroll">
  <table class="datatable">
    <thead>
      <tr><th>#</th><th>Requested</th><th>Customer</th><th>Vehicle</th><th>Status</th><th>Tireman</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($requests as $r): ?>
      <tr>
        <td class="cell-strong"><?= (int) $r['request_id'] ?></td>
        <td class="muted nowrap"><?= e($r['requested_at']) ?></td>
        <td class="cell-strong"><?= e($r['customer_name']) ?></td>
        <td class="nowrap"><?= e($r['plate_number']) ?></td>
        <td>
          <span class="badge <?= e(OtgStatus::badgeClass($r['status'])) ?>"><?= e(OtgStatus::adminLabel($r['status'])) ?></span>
        </td>
        <td><?= $r['tireman_name'] !== null ? e($r['tireman_name']) : '<span class="muted">Unassigned</span>' ?></td>
        <td class="rowactions">
          <a href="<?= e(vulcatrack_url('/admin/rescue-view.php?id=' . (int) $r['request_id'])) ?>">View</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="tablepanel__foot"><?= $n ?> <?= $n === 1 ? 'request' : 'requests' ?>.</p>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
