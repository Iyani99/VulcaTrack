<?php
/**
 * Admin dashboard — landing page for the signed-in admin.
 *
 * Phase 7.2: "Dashboard Overview" with three live summary cards, following the
 * approved Figma dashboard frame (without its activity feed, comparisons or
 * notification / help icons — not in scope). Phase 7.3a moved it into the
 * shared admin sidebar shell; the signed-in admin's name now sits in the
 * sidebar. Each figure reuses the exact read behind the page its card links
 * to, so the numbers always agree:
 *
 * - Total Sales Today — SaleRepository::summarize(today, today) on sale_date
 *   (Decision 35), "today" in the app timezone → Reports for today.
 * - Low Stock Alerts — ItemRepository::lowStockProducts(), the Inventory
 *   low-stock rule (active products at or below their reorder level; services
 *   never count) → Inventory with ?low_stock=1.
 * - Pending Rescues — ServiceRequestRepository::listForAdmin('pending'), the
 *   Rescue list's default view → Rescue.
 *
 * Read-only: GET, admin guard, no forms.
 */

use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Support\Money;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$pdo   = vulcatrack_db();

$today      = date('Y-m-d'); // app timezone (config app.timezone), same as sale_date
$salesToday = (new SaleRepository($pdo))->summarize($today, $today);
$lowStock   = count((new ItemRepository($pdo))->lowStockProducts());
$pending    = count((new ServiceRequestRepository($pdo))->listForAdmin('pending'));

$pageTitle = 'Dashboard';
$navActive = 'dashboard';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<header class="pagehead">
  <div>
    <h1>Dashboard Overview</h1>
    <p class="pagehead__meta">Figures for <?= e($today) ?></p>
  </div>
  <a class="btnlink" href="<?= e(vulcatrack_url('/admin/pos.php')) ?>">+ New sale</a>
</header>

<div class="dash-cards">
  <section class="dash-card">
    <h2 class="dash-card__label">
      <svg class="dash-card__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 6h20v12H2zm2 2v8h16V8zm8 1.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM5 10h2v4H5zm12 0h2v4h-2z"/></svg>
      Total Sales (Today)
    </h2>
    <p class="dash-card__value">&#8369;<?= e(Money::format((int) $salesToday['total_centavos'])) ?></p>
    <p class="dash-card__note"><?= (int) $salesToday['count'] ?> <?= (int) $salesToday['count'] === 1 ? 'transaction' : 'transactions' ?> recorded today</p>
    <a class="dash-card__link" href="<?= e(vulcatrack_url('/admin/reports.php?from=' . $today . '&to=' . $today)) ?>">View today's report</a>
  </section>

  <section class="dash-card dash-card--alert">
    <h2 class="dash-card__label">
      <svg class="dash-card__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 1 21h22zm-1 7h2v6h-2zm0 8h2v2h-2z"/></svg>
      Low Stock Alerts
    </h2>
    <p class="dash-card__value"><?= $lowStock ?></p>
    <p class="dash-card__note">Active products at or below their reorder level</p>
    <a class="dash-card__link" href="<?= e(vulcatrack_url('/admin/inventory.php?low_stock=1')) ?>">View low-stock items</a>
  </section>

  <section class="dash-card">
    <h2 class="dash-card__label">
      <svg class="dash-card__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>
      Pending Rescues
    </h2>
    <p class="dash-card__value"><?= $pending ?></p>
    <p class="dash-card__note">Waiting to be accepted or rejected</p>
    <a class="dash-card__link" href="<?= e(vulcatrack_url('/admin/rescue.php?status=pending')) ?>">Open Rescue requests</a>
  </section>
</div>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
