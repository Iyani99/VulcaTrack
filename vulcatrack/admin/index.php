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
 * Needs Attention (Phase 7.3b): the first few low-stock items and pending
 * requests, rendered from those same two reads (no extra query), each list
 * linking to its full page. No activity feed or comparisons (not in scope).
 * The seven-day Sales Trend uses SaleRepository::listDailyTotals() and
 * ReportChart::fillDays() to show every calendar day, including zero days.
 *
 * Read-only: GET, admin guard, no forms.
 */

use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Support\Money;
use VulcaTrack\Support\ReportChart;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$pdo   = vulcatrack_db();

$today      = date('Y-m-d'); // app timezone (config app.timezone), same as sale_date
$saleRepo   = new SaleRepository($pdo);
$salesToday = $saleRepo->summarize($today, $today);
$trendFrom  = (new DateTimeImmutable($today))->modify('-6 days')->format('Y-m-d');
$trendDays  = ReportChart::fillDays($saleRepo->listDailyTotals($trendFrom, $today), $trendFrom, 7);
$trendMax   = max(array_column($trendDays, 'total_centavos'));
$trendScale = ReportChart::scale($trendMax);
$trendTotal = array_sum(array_column($trendDays, 'total_centavos'));
$trendY     = static fn (int $centavos): float => 146 - ($trendScale['max'] > 0 ? $centavos / $trendScale['max'] * 132 : 0);
$trendPoints = [];
foreach ($trendDays as $i => $day) {
    $trendPoints[] = (50 + $i * 100) . ',' . round($trendY($day['total_centavos']), 1);
}
// The same two reads feed both the card counts and the Needs Attention lists
// below (Phase 7.3b) — no extra query.
$lowItems   = (new ItemRepository($pdo))->lowStockProducts();          // by item name
$pendingReq = (new ServiceRequestRepository($pdo))->listForAdmin('pending'); // newest first
$lowStock   = count($lowItems);
$pending    = count($pendingReq);
const DASH_LIST_MAX = 5; // rows per Needs Attention list; the rest are one link away

$pageTitle = 'Dashboard';
$navActive = 'dashboard';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<header class="pagehead pagehead--dashboard">
  <div>
    <h1>Dashboard Overview</h1>
    <p class="pagehead__meta">Figures for <?= e($today) ?></p>
  </div>
  <a class="btnlink btnlink--new-sale" href="<?= e(vulcatrack_url('/admin/pos.php')) ?>">+ New sale</a>
</header>

<div class="dash-cards">
  <section class="dash-card">
    <h2 class="dash-card__label">
      <svg class="dash-card__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 6h20v12H2zm2 2v8h16V8zm8 1.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM5 10h2v4H5zm12 0h2v4h-2z"/></svg>
      Total Sales (Today)
    </h2>
    <p class="dash-card__value">&#8369;<?= e(Money::formatDisplay((int) $salesToday['total_centavos'])) ?></p>
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

<h2 class="dash-section">Needs Attention</h2>
<div class="panelgrid panelgrid--dashboard">
  <section class="panel">
    <header class="panel__head"><h3>Low stock items</h3></header>
    <?php if (!$lowItems): ?>
      <p class="panel__note muted">No products are at or below their reorder level.</p>
    <?php else: ?>
      <ul class="attn-list">
        <?php foreach (array_slice($lowItems, 0, DASH_LIST_MAX) as $it): ?>
          <li>
            <span class="attn-list__main"><?= e($it['item_name']) ?></span>
            <span class="stock stock--low"><?= (int) $it['stock_quantity'] === 0 ? 'Out: 0' : 'Low: ' . (int) $it['stock_quantity'] ?></span>
            <span class="attn-list__meta">reorder at <?= (int) $it['reorder_level'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p class="panel__foot">
      <a href="<?= e(vulcatrack_url('/admin/inventory.php?low_stock=1')) ?>"><?= $lowStock > DASH_LIST_MAX ? 'View all ' . $lowStock . ' low-stock items' : 'View in Inventory' ?></a>
    </p>
  </section>

  <section class="panel">
    <header class="panel__head"><h3>Pending rescues</h3></header>
    <?php if (!$pendingReq): ?>
      <p class="panel__note muted">No pending rescue requests.</p>
    <?php else: ?>
      <ul class="attn-list">
        <?php foreach (array_slice($pendingReq, 0, DASH_LIST_MAX) as $rq): ?>
          <li>
            <a class="attn-list__main" href="<?= e(vulcatrack_url('/admin/rescue-view.php?id=' . (int) $rq['request_id'])) ?>">#<?= (int) $rq['request_id'] ?> &middot; <?= e($rq['customer_name']) ?></a>
            <span class="attn-list__meta"><?= e($rq['plate_number']) ?> &middot; <?= e($rq['requested_at']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p class="panel__foot">
      <a href="<?= e(vulcatrack_url('/admin/rescue.php?status=pending')) ?>"><?= $pending > DASH_LIST_MAX ? 'View all ' . $pending . ' pending requests' : 'View in Rescue' ?></a>
    </p>
  </section>
</div>

<section class="panel dash-trend" aria-labelledby="dash-trend-title">
  <header class="panel__head dash-trend__head">
    <div>
      <h2 id="dash-trend-title">Sales Trend — Last 7 Days</h2>
      <p class="dash-trend__range"><?= e((new DateTimeImmutable($trendFrom))->format('M j')) ?> – <?= e((new DateTimeImmutable($today))->format('M j, Y')) ?></p>
    </div>
    <p class="dash-trend__total">7-day total <strong>&#8369;<?= e(Money::formatDisplay($trendTotal)) ?></strong></p>
  </header>
  <div class="dash-trend__body">
    <div class="dash-trend__plot">
      <div class="dash-trend__axis" aria-hidden="true">
        <span>&#8369;<?= e(Money::formatDisplay($trendScale['max'])) ?></span>
        <span>&#8369;0</span>
      </div>
      <svg viewBox="0 0 700 160" preserveAspectRatio="none" aria-hidden="true">
        <line class="dash-trend__grid" x1="8" y1="14" x2="692" y2="14"/>
        <line class="dash-trend__grid" x1="8" y1="146" x2="692" y2="146"/>
        <polyline class="dash-trend__line" pathLength="1" points="<?= e(implode(' ', $trendPoints)) ?>"/>
      </svg>
    </div>
    <div class="dash-trend__xlabels" aria-hidden="true">
      <?php foreach ($trendDays as $day): ?><span><?= e((new DateTimeImmutable($day['day']))->format('D')) ?></span><?php endforeach; ?>
    </div>
    <ol class="dash-trend__days">
      <?php foreach ($trendDays as $day): ?>
        <li data-day="<?= e($day['day']) ?>" data-centavos="<?= (int) $day['total_centavos'] ?>">
          <span class="dash-trend__day"><?= e((new DateTimeImmutable($day['day']))->format('D, M j')) ?></span>
          <strong>&#8369;<?= e(Money::formatDisplay($day['total_centavos'])) ?></strong>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
