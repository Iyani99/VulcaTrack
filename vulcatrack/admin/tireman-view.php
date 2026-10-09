<?php
/** Read-only Tireman detail from existing record and Rescue assignments. */
use VulcaTrack\Repository\TiremanRepository;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$repo = new TiremanRepository(vulcatrack_db());
$rawId = $_GET['id'] ?? null;
$tiremanId = is_string($rawId) && preg_match('/^[1-9]\d{0,9}$/D', $rawId) === 1 && (int) $rawId <= 2147483647
    ? (int) $rawId : 0;
$tireman = $tiremanId > 0 ? $repo->findById($tiremanId) : null;

$pageTitle = $tireman === null ? 'Tireman not found' : (string) $tireman['name'];
$navActive = 'tiremen';
if ($tireman === null) {
    http_response_code(404);
    require __DIR__ . '/../src/Views/partials/admin_top.php';
    $notFoundTitle = 'Tireman not found';
    $notFoundMessage = 'No Tireman has that number.';
    $notFoundBackUrl = vulcatrack_url('/admin/tiremen.php');
    $notFoundBackLabel = 'Back to Tiremen';
    require __DIR__ . '/../src/Views/partials/not_found.php';
    require __DIR__ . '/../src/Views/partials/admin_bottom.php';
    exit;
}

$assignments = $repo->listAssignments($tiremanId);
$completedCount = count(array_filter($assignments, static fn (array $row): bool => $row['status'] === 'completed'));
$isActive = $tireman['is_active'] === 1;
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead tireman-detail__head">
  <div>
    <p class="tireman-detail__back"><a href="<?= e(vulcatrack_url('/admin/tiremen.php')) ?>">&larr; Tiremen</a></p>
    <h1><?= e($tireman['name']) ?></h1>
    <p class="pagehead__meta">Tireman record and assigned Rescue history.</p>
  </div>
  <a class="btnlink" href="<?= e(vulcatrack_url('/admin/tireman-edit.php?id=' . $tiremanId)) ?>">Edit Tireman</a>
</div>

<div class="tireman-detail__grid">
  <section class="panel" aria-labelledby="tireman-info-title">
    <header class="panel__head"><h2 id="tireman-info-title">Profile</h2></header>
    <dl class="tireman-detail__facts">
      <div><dt>Status</dt><dd><span class="badge badge--<?= $isActive ? 'active' : 'inactive' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span></dd></div>
      <div><dt>Contact number</dt><dd><?= e($tireman['contact_number']) ?></dd></div>
      <div><dt>Added</dt><dd><?= e(date('M j, Y', strtotime((string) $tireman['created_at']))) ?></dd></div>
      <div><dt>Updated</dt><dd><?= e(date('M j, Y', strtotime((string) $tireman['updated_at']))) ?></dd></div>
    </dl>
  </section>
  <section class="panel" aria-labelledby="tireman-work-title">
    <header class="panel__head"><h2 id="tireman-work-title">Rescue work</h2></header>
    <dl class="tireman-detail__facts tireman-detail__facts--counts">
      <div><dt>Assigned rescues</dt><dd><?= count($assignments) ?></dd></div>
      <div><dt>Completed</dt><dd><?= $completedCount ?></dd></div>
    </dl>
    <p class="panel__note muted">Counts reflect requests currently assigned to this Tireman, including completed history.</p>
  </section>
</div>

<section class="panel tireman-detail__history" aria-labelledby="tireman-history-title">
  <header class="panel__head"><h2 id="tireman-history-title">Assigned Rescue history</h2></header>
  <?php if (!$assignments): ?>
    <p class="panel__note muted">No Rescue requests have been assigned yet.</p>
  <?php else: ?>
    <ul class="tireman-history">
      <?php foreach ($assignments as $request): ?>
        <li>
          <div>
            <a class="tireman-history__link" href="<?= e(vulcatrack_url('/admin/rescue-view.php?id=' . (int) $request['request_id'])) ?>">Rescue #<?= (int) $request['request_id'] ?></a>
            <p><?= e($request['customer_name']) ?> &middot; <?= e(trim((string) $request['make'] . ' ' . (string) $request['model'])) ?> &middot; <?= e($request['plate_number']) ?></p>
            <time datetime="<?= e(date('Y-m-d', strtotime((string) $request['requested_at']))) ?>"><?= e(date('M j, Y', strtotime((string) $request['requested_at']))) ?></time>
          </div>
          <span class="badge badge--<?= e($request['status']) ?>"><?= e(ucfirst((string) $request['status'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
