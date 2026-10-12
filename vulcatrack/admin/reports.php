<?php
/**
 * Admin — Sales Reports and current Inventory Intelligence.
 *
 * All summaries, tables and visualizations use one validated inclusive sales
 * date interval. Daily charts fill zero days; the rolling 365-day option uses
 * calendar-month buckets, including partial boundary months and empty months.
 * The Daily Sales table remains the exact-value view. The source panel splits
 * recorded sales into in-shop and Rescue-linked revenue and counts.
 *
 * Not here (not approved): an item-type column or product / service totals,
 * walk-in / customer splits, averages, growth or target figures, exports,
 * pagination.
 */

use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\InventorySettingsRepository;
use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Auth\Csrf;
use VulcaTrack\Support\Money;
use VulcaTrack\Support\ReportChart;
use VulcaTrack\Support\ReportPeriod;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();

/** Recorded centavos → compact UI peso text (escaped). */
function reports_peso(int $centavos): string
{
    return '&#8369;' . e(Money::formatDisplay($centavos));
}

/** 'YYYY-MM-DD' → "Sep 29, 2026" (or "Sep 29" when $short). */
function reports_day(string $day, bool $short = false, bool $monthly = false): string
{
    return e((new DateTimeImmutable(strlen($day) === 7 ? $day . '-01' : $day))->format($monthly ? 'M Y' : ($short ? 'M j' : 'M j, Y')));
}

/** A y-axis tick (whole pesos by construction) → "₱30,000". */
function reports_axis_peso(int $centavos): string
{
    return '&#8369;' . number_format(intdiv($centavos, 100));
}

/** A percentage for an SVG x / width attribute. */
function reports_pct(float $value): string
{
    return sprintf('%.4F%%', $value);
}

/** Keep the validated main period when a chart point is selected or cleared. */
function reports_period_url(array $period, ?string $focus = null): string
{
    $query = ['period' => $period['period']];
    if ($period['month'] !== null) { $query['month'] = $period['month']; }
    if ($focus !== null) { $query['focus'] = $focus; }
    return vulcatrack_url('/admin/reports.php') . '?' . http_build_query($query);
}

$today = date('Y-m-d');
$pdo = vulcatrack_db();
$sales = new SaleRepository($pdo);
$period = ReportPeriod::resolve($_GET['period'] ?? null, $_GET['month'] ?? null, $today, '30d', true);
$asOf = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$inventoryRepo = new ItemRepository($pdo);
$settingsRepo = new InventorySettingsRepository($pdo);
$settingError = null;
$settings = null;
$slowMoving = [];
$lowStock = $inventoryRepo->lowStockProducts();
$thresholdInput = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $thresholdInput = is_string($_POST['slow_moving_days'] ?? null) ? trim($_POST['slow_moving_days']) : '';
    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $settingError = 'Your session expired. Please try again.';
    } elseif (($_POST['_action'] ?? '') !== 'update_slow_moving_days'
        || preg_match('/^[0-9]{1,3}$/D', $thresholdInput) !== 1
        || (int) $thresholdInput < 1 || (int) $thresholdInput > 365) {
        $settingError = 'Enter a slow-moving threshold from 1 to 365 days.';
    } else {
        try {
            $changed = $settingsRepo->updateSlowMovingDays((int) $thresholdInput, (int) $admin['id']);
            header('Location: ' . reports_period_url($period) . '&inventory=' . ($changed ? 'saved' : 'unchanged') . '#inventory-intelligence');
            exit;
        } catch (\PDOException | \RuntimeException $e) {
            $settingError = 'Inventory settings could not be saved. Please try again.';
        }
    }
}
try {
    // Read the threshold once. The same value drives the cutoff, list and copy.
    $settings = $settingsRepo->current();
    $cutoff = $asOf->sub(new DateInterval('P' . $settings['slow_moving_days'] . 'D'));
    $slowMoving = $inventoryRepo->slowMovingProducts($cutoff->format('Y-m-d H:i:s'));
} catch (\PDOException | \RuntimeException $e) {
    $settings = null;
    $slowMoving = [];
    $settingError = $settingError ?? 'Inventory Intelligence is unavailable until its settings are restored.';
}
$monthOptions = ReportPeriod::monthOptions($sales->listSaleMonths(), $today, $period['month']);
$from = $period['from'];
$to = $period['to'];
$rangeLabel = $period['label'] . ': ' . $from . ($from === $to ? '' : ' to ' . $to);

$summary = $sales->summarize($from, $to);
$resolvedRescues = (new ServiceRequestRepository(vulcatrack_db()))->resolvedRequestedBetween($from, $to);
$resolvedCount = $resolvedRescues['completed'] + $resolvedRescues['rejected'];
$rescueRate = $resolvedCount > 0
    ? rtrim(rtrim(number_format($resolvedRescues['completed'] * 100 / $resolvedCount, 1, '.', ''), '0'), '.') . '%'
    : null;
$daily   = $sales->listDailyTotals($from, $to);
$items   = $sales->listItemTotals($from, $to);
$sources = $sales->summarizeBySource($from, $to);
$shares  = ReportChart::shares($sources['in_shop']['total_centavos'], $sources['rescue']['total_centavos']);
$empty   = 'No sales in this date range.';

// The chart uses exactly the same inclusive range as every other report figure.
$days = $period['monthly']
    ? array_map(static fn (array $row): array => ['day' => $row['month'], 'transaction_count' => $row['transaction_count'], 'total_centavos' => $row['total_centavos']],
        ReportChart::fillMonths($sales->listMonthlyTotals($from, $to), $from, $to))
    : ReportChart::fillDays($daily, $from, $period['days']);
$slotCount = count($days);
$explicitFocus = ReportPeriod::resolveFocus($_GET['focus'] ?? null, $period);
$focus = $explicitFocus ?? ($period['period'] === 'today' ? $from : null);
$focusRow = null;
foreach ($days as $day) {
    if ($day['day'] === $focus) { $focusRow = $day; break; }
}
$focusFrom = $focus === null ? null : ($period['monthly'] ? max($from, $focus . '-01') : $focus);
$focusTo = $focus === null ? null : ($period['monthly']
    ? min($to, (new DateTimeImmutable($focus . '-01'))->modify('last day of this month')->format('Y-m-d'))
    : $focus);
$winTotal = 0;
$winCount = 0;
$peak     = null; // index of the (first) highest period
foreach ($days as $i => $d) {
    $winTotal += $d['total_centavos'];
    $winCount += $d['transaction_count'];
    if ($d['total_centavos'] > 0 && ($peak === null || $d['total_centavos'] > $days[$peak]['total_centavos'])) {
        $peak = $i;
    }
}
$transactionCount = (int) $summary['count'];
$totalCentavos = (int) $summary['total_centavos'];
// Round the integer-centavo quotient to the nearest centavo, half up.
$averageCentavos = $transactionCount > 0
    ? intdiv($totalCentavos, $transactionCount)
        + ((($totalCentavos % $transactionCount) >= intdiv($transactionCount + 1, 2)) ? 1 : 0)
    : null;
$transactionContext = $period['period'] === 'today' ? 'For today.'
    : ($period['period'] === 'month' ? 'Across ' . $period['label'] . '.'
    : 'Across the selected ' . $period['days'] . '-day range.');
$scale = ReportChart::scale($peak === null ? 0 : $days[$peak]['total_centavos']);
$ticks = $scale['step'] > 0 ? range(0, $scale['max'], $scale['step']) : [0]; // centavos; no sales → just ₱0
// Line geometry: the same validated buckets drive chart coordinates and focus.
$chartH   = 238;
$plotTop  = 22;
$baseline = 188;
$plotH    = $baseline - $plotTop;
$slot     = 100 / $slotCount;
$yOf      = fn (int $c): float => $baseline - ($scale['max'] > 0 ? $c / $scale['max'] * $plotH : 0);
$lineCoords = [];
foreach ($days as $i => $day) {
    $lineCoords[] = sprintf('%.2F %.2F', ($i + 0.5) * 1000 / $slotCount, $yOf($day['total_centavos']));
}
$linePath = 'M ' . implode(' L ', $lineCoords);
$chartMobileWidth = max(280, $slotCount * 28);

$pageTitle = 'Reports';
$navActive = 'reports';
$useReportMotion = true;
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <div>
    <h1>Sales Reports</h1>
    <p class="pagehead__meta">Totals from recorded sales, by sale date. Individual transactions are in Sales History.</p>
  </div>
  <?php $periodAction = vulcatrack_url('/admin/reports.php'); $includeToday = true; require __DIR__ . '/../src/Views/partials/report_period_filter.php'; ?>
</div>

  <p class="report-range">Range: <strong><?= e($rangeLabel) ?></strong></p>

  <div class="cardgrid">
    <section class="card card--stat rpt-summary-card">
      <p class="card__label">Transactions</p>
      <p class="card__num"><?= $transactionCount ?></p>
      <?php if ($transactionCount === 0): ?>
        <p class="rpt-summary-card__context">No transactions in this period.</p>
      <?php else: ?>
        <p class="rpt-summary-card__context"><?= (int) $sources['in_shop']['count'] ?> in-shop &middot; <?= (int) $sources['rescue']['count'] ?> Rescue-linked</p>
        <p class="rpt-summary-card__note"><?= e($transactionContext) ?></p>
      <?php endif; ?>
    </section>
    <section class="card card--stat card--accent rpt-summary-card">
      <p class="card__label">Total Sales</p>
      <p class="card__num"><?= reports_peso($totalCentavos) ?></p>
      <p class="rpt-summary-card__context">Average sale: <?= $averageCentavos === null ? '&mdash;' : reports_peso($averageCentavos) ?></p>
      <?php if ($peak !== null): ?>
        <p class="rpt-summary-card__note">Peak <?= $period['monthly'] ? 'month' : 'day' ?>: <strong><?= reports_day($days[$peak]['day'], false, $period['monthly']) ?> &middot; <?= reports_peso((int) $days[$peak]['total_centavos']) ?></strong></p>
      <?php else: ?>
        <p class="rpt-summary-card__note"><?= $transactionCount === 0 ? 'No sales in this period.' : 'No sales revenue in this period.' ?></p>
      <?php endif; ?>
    </section>
    <section class="card card--stat rpt-rescue-rate" aria-label="Rescue Success Rate for the selected report period">
      <p class="card__label">Rescue Success Rate</p>
      <p class="card__num"><?= $rescueRate === null ? '&mdash;' : e($rescueRate) ?></p>
      <p class="rpt-rescue-rate__context"><?= $resolvedCount === 0
          ? 'No resolved rescues'
          : (int) $resolvedRescues['completed'] . ' of ' . $resolvedCount . ' resolved rescues completed' ?></p>
      <p class="rpt-rescue-rate__note">Requests submitted in this range, by current status. Pending and accepted are excluded.</p>
    </section>
  </div>

  <div class="rpt-visuals">
  <section class="panel rpt-chart" aria-labelledby="rpt-chart-h">
    <header class="panel__head">
      <h2 id="rpt-chart-h">Sales Performance</h2>
      <p class="rpt-chart__window">
        <?= $period['monthly'] ? 'Monthly' : 'Daily' ?> sales, <strong><?= reports_day($from) ?> &ndash; <?= reports_day($to) ?></strong>
        (<?= $slotCount ?> <?= $period['monthly'] ? ($slotCount === 1 ? 'month' : 'months') : ($slotCount === 1 ? 'day' : 'days') ?>)
      </p>
    </header>
    <p class="panel__note rpt-chart__total">
      Chart total: <strong><?= reports_peso($winTotal) ?></strong>
      from <?= $winCount ?> <?= $winCount === 1 ? 'transaction' : 'transactions' ?>
    </p>
    <div class="rpt-plot" role="group" aria-label="Sales performance line chart; select a point to focus its period">
      <div class="rpt-plot__scroll" tabindex="0" aria-label="Sales graph; scroll horizontally for more dates">
        <div class="rpt-plot__canvas" style="--rpt-mobile-width: <?= $chartMobileWidth ?>px">
          <svg class="rpt-plot__line" width="100%" height="<?= $chartH ?>" viewBox="0 0 1000 <?= $chartH ?>" preserveAspectRatio="none" aria-hidden="true">
            <?php foreach ($ticks as $tick): if ($tick > 0): ?>
              <line class="rpt-grid" x1="0" x2="1000" y1="<?= round($yOf($tick), 1) ?>" y2="<?= round($yOf($tick), 1) ?>"/>
            <?php endif; endforeach; ?>
            <line class="rpt-base" x1="0" x2="1000" y1="<?= $baseline ?>" y2="<?= $baseline ?>"/>
            <?php if ($slotCount > 1): ?>
              <path class="rpt-line" pathLength="1" d="<?= e($linePath) ?>"/>
            <?php endif; ?>
          </svg>
          <?php foreach ($days as $i => $day):
              $dayKey = $day['day'];
              $dayTotal = (int) $day['total_centavos'];
              $dayCount = (int) $day['transaction_count'];
              $pointLabel = (new DateTimeImmutable($period['monthly'] ? $dayKey . '-01' : $dayKey))
                  ->format($period['monthly'] ? 'F Y' : 'F j, Y');
          ?>
            <a class="rpt-point<?= $focus === $dayKey ? ' is-selected' : '' ?>"
               href="<?= e(reports_period_url($period, $dayKey) . '#focused-period') ?>"
               style="left: <?= reports_pct(($i + 0.5) * $slot) ?>; top: <?= round($yOf($dayTotal), 1) ?>px; width: min(24px, <?= reports_pct($slot * .9) ?>)"
               aria-label="<?= e($pointLabel . ': ₱' . Money::formatDisplay($dayTotal) . ' in sales from ' . $dayCount . ($dayCount === 1 ? ' transaction' : ' transactions') . '. View details.') ?>"
               <?= $focus === $dayKey ? 'aria-current="true"' : '' ?>>
              <span class="rpt-point__dot" aria-hidden="true"></span>
            </a>
          <?php endforeach; ?>
          <?php $labels = ReportChart::labelIndexes($slotCount); $lastLabel = end($labels);
          foreach ($labels as $i): ?>
            <span class="rpt-xlabel<?= $i === 0 ? ' rpt-xlabel--first' : ($i === $lastLabel ? ' rpt-xlabel--last' : '') ?>"
                  style="left: <?= reports_pct(($i + 0.5) * $slot) ?>"><?= reports_day($days[$i]['day'], true, $period['monthly']) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <svg class="rpt-plot__axis" width="76" height="<?= $chartH ?>" aria-hidden="true">
        <?php foreach ($ticks as $tick): ?>
          <text class="rpt-ytick" x="8" y="<?= round($yOf($tick) + 4, 1) ?>"><?= reports_axis_peso($tick) ?></text>
        <?php endforeach; ?>
      </svg>
    </div>
    <p class="panel__foot muted"><?= $period['monthly'] ? 'Months' : 'Days' ?> without sales remain at &#8369;0. Select a chart point to view its details; the graph scrolls on small screens.</p>
    <?php if ($focusRow !== null): ?>
      <section class="rpt-focus" id="focused-period" aria-labelledby="rpt-focus-title">
        <div class="rpt-focus__head">
          <div>
            <p class="rpt-focus__eyebrow">Focused <?= $period['monthly'] ? 'month' : 'date' ?></p>
            <h3 id="rpt-focus-title"><?= reports_day($focus, false, $period['monthly']) ?></h3>
          </div>
          <?php if ($explicitFocus !== null && $period['period'] !== 'today'): ?>
            <a href="<?= e(reports_period_url($period)) ?>">Whole interval</a>
          <?php endif; ?>
        </div>
        <?php if ($period['monthly'] && ($focusFrom !== $focus . '-01' || $focusTo !== (new DateTimeImmutable($focus . '-01'))->modify('last day of this month')->format('Y-m-d'))): ?>
          <p class="rpt-focus__scope">Within the selected report range: <?= e($focusFrom) ?> to <?= e($focusTo) ?></p>
        <?php endif; ?>
        <dl class="rpt-focus__stats">
          <div><dt>Transactions</dt><dd><?= (int) $focusRow['transaction_count'] ?></dd></div>
          <div><dt>Sales total</dt><dd><?= reports_peso((int) $focusRow['total_centavos']) ?></dd></div>
        </dl>
        <a class="rpt-focus__sales" href="<?= e(vulcatrack_url('/admin/sales.php') . '?' . http_build_query(['from' => $focusFrom, 'to' => $focusTo])) ?>">View in Sales History &rarr;</a>
      </section>
    <?php endif; ?>
  </section>

  <section class="panel rpt-source" aria-labelledby="rpt-source-h">
    <header class="panel__head"><h2 id="rpt-source-h">Sales by Source</h2></header>
    <p class="panel__note muted">For <strong><?= e($rangeLabel) ?></strong>. In-shop: sales not linked to a Rescue request. Rescue: sales recorded for one.</p>
    <?php if ((int) $summary['count'] === 0): ?>
      <p class="panel__note muted"><?= e($empty) ?></p>
    <?php else: ?>
      <ul class="rpt-src">
        <?php foreach ([['in_shop', 'In-shop', 0], ['rescue', 'Rescue', 1]] as [$key, $label, $si]):
            $src = $sources[$key]; ?>
          <li class="rpt-src__row rpt-src__row--<?= $key === 'rescue' ? 'rescue' : 'inshop' ?>">
            <p class="rpt-src__head">
              <span class="rpt-src__name"><?= $label ?></span>
              <span class="rpt-src__share"><?= $shares !== null ? $shares[$si] . '%' : '&mdash;' ?> <small>of revenue</small></span>
            </p>
            <div class="rpt-src__meter" aria-hidden="true"><span style="width: <?= $shares !== null ? $shares[$si] : '0' ?>%"></span></div>
            <dl class="rpt-src__facts">
              <div><dt>Transactions</dt><dd><?= (int) $src['count'] ?></dd></div>
              <div><dt>Revenue</dt><dd><?= reports_peso((int) $src['total_centavos']) ?></dd></div>
            </dl>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($shares === null): ?>
        <p class="panel__foot muted">No revenue in this range, so no revenue share is shown.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  </div>

  <div class="panelgrid">
  <section class="panel">
    <header class="panel__head"><h2>Daily Sales</h2></header>
    <?php if (!$daily): ?>
      <p class="panel__note muted"><?= e($empty) ?></p>
    <?php else: ?>
      <p class="panel__note muted">Days without sales are not listed.<?= $focusRow !== null ? ' Dates in the focused period are marked below.' : '' ?></p>
      <div class="table-scroll">
      <table class="datatable">
        <thead>
          <tr><th>Date</th><th class="num">Transactions</th><th class="num">Total Sales</th></tr>
        </thead>
        <tbody>
        <?php foreach ($daily as $d):
            $rowFocused = $focus !== null && ($period['monthly'] ? substr($d['day'], 0, 7) === $focus : $d['day'] === $focus); ?>
          <tr<?= $rowFocused ? ' class="rpt-row--focused" aria-current="true"' : '' ?>>
            <td><?= $rowFocused ? '<span class="rpt-row__marker">Focus</span> ' : '' ?><?= e($d['day']) ?></td>
            <td class="num"><?= (int) $d['transaction_count'] ?></td>
            <td class="num"><?= reports_peso((int) $d['total_centavos']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <header class="panel__head"><h2>Items Sold</h2></header>
    <?php if (!$items): ?>
      <p class="panel__note muted"><?= e($empty) ?></p>
    <?php else: ?>
      <p class="panel__note muted">Revenue uses the price recorded at the time of each sale.</p>
      <div class="table-scroll">
      <table class="datatable">
        <thead>
          <tr><th>Item</th><th class="num">Qty Sold</th><th class="num">Revenue</th></tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= e($it['item_name']) ?></td>
            <td class="num"><?= (int) $it['quantity'] ?></td>
            <td class="num"><?= reports_peso((int) $it['revenue_centavos']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>
  </div>
  <section class="rpt-intelligence" id="inventory-intelligence" aria-labelledby="rpt-intelligence-h">
    <div class="rpt-intelligence__intro">
      <div>
        <p class="rpt-intelligence__eyebrow">Current inventory</p>
        <h2 id="rpt-intelligence-h">Inventory Intelligence</h2>
        <p>Live shop stock and recorded Sales inactivity as of <?= e($asOf->format('M j, Y g:i A')) ?> Asia/Manila. Sales period controls above do not change this section.</p>
      </div>
    </div>
    <?php if (($_GET['inventory'] ?? '') === 'saved'): ?><p class="notice" role="status">Slow-moving threshold updated.</p><?php endif; ?>
    <?php if (($_GET['inventory'] ?? '') === 'unchanged'): ?><p class="notice" role="status">Slow-moving threshold was already set to that value.</p><?php endif; ?>
    <?php if ($settingError !== null): ?><p class="error" role="alert"><?= e($settingError) ?></p><?php endif; ?>

    <div class="panelgrid rpt-intelligence__grid">
      <section class="panel" aria-labelledby="rpt-low-h">
        <header class="panel__head rpt-intelligence__panelhead"><h3 id="rpt-low-h">Low stock <span class="rpt-intelligence__count"><?= count($lowStock) ?></span></h3><a href="<?= e(vulcatrack_url('/admin/inventory.php?low_stock=1')) ?>">Open Inventory &rarr;</a></header>
        <p class="panel__note muted">Active Products at or below their own reorder level, including zero stock.</p>
        <?php if (!$lowStock): ?>
          <p class="panel__note muted">No low-stock Products.</p>
        <?php else: ?>
          <div class="table-scroll"><table class="datatable mobile-records">
            <thead><tr><th>Product</th><th class="num">Shop stock</th><th class="num">Reorder level</th></tr></thead>
            <tbody><?php foreach ($lowStock as $product): ?>
              <tr><td data-label="Product" data-record-primary><a href="<?= e(vulcatrack_url('/admin/item-edit.php?id=' . (int) $product['item_id'])) ?>"><?= e($product['item_name']) ?></a></td><td class="num" data-label="Shop stock"><?= (int) $product['stock_quantity'] ?></td><td class="num" data-label="Reorder level"><?= (int) $product['reorder_level'] ?></td></tr>
            <?php endforeach; ?></tbody>
          </table></div>
        <?php endif; ?>
      </section>
      <section class="panel rpt-intelligence__settings" aria-labelledby="rpt-threshold-h">
        <header class="panel__head"><h3 id="rpt-threshold-h">Slow-moving threshold</h3></header>
        <?php if ($settings !== null): ?>
          <p class="panel__note">Currently <strong><?= $settings['slow_moving_days'] ?> days</strong>. All current Admins may change this advisory period.</p>
          <form class="rpt-intelligence__form" method="post" action="<?= e(reports_period_url($period) . '#inventory-intelligence') ?>" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="_action" value="update_slow_moving_days">
            <label for="slow_moving_days">Days <span class="muted">(1–365)</span></label>
            <input id="slow_moving_days" name="slow_moving_days" type="number" min="1" max="365" step="1" inputmode="numeric" required value="<?= e($settingError !== null && $thresholdInput !== '' ? $thresholdInput : (string) $settings['slow_moving_days']) ?>"<?= $settingError !== null ? ' aria-invalid="true"' : '' ?>>
            <button type="submit">Update threshold</button>
          </form>
          <?php if ($settings['updated_by_admin_name'] !== null): ?><p class="panel__foot muted">Last changed by <?= e($settings['updated_by_admin_name']) ?> · <?= e((new DateTimeImmutable($settings['updated_at']))->format('M j, Y g:i A')) ?></p><?php endif; ?>
        <?php else: ?>
          <p class="panel__note muted">Threshold unavailable. Restore the single settings row before using slow-moving results.</p>
        <?php endif; ?>
      </section>
    </div>

    <section class="panel rpt-intelligence__slow" aria-labelledby="rpt-slow-h">
      <header class="panel__head"><h3 id="rpt-slow-h">Slow-moving Products<?= $settings !== null ? ' · ' . count($slowMoving) : '' ?></h3></header>
      <?php if ($settings !== null): ?>
        <p class="panel__note">No recorded sales in the configured <strong><?= $settings['slow_moving_days'] ?>-day period</strong>. This is a Sales-performance signal, not Product age or deterioration. Previous stockouts are not measured.</p>
        <?php if (!$slowMoving): ?>
          <p class="panel__note muted">No slow-moving Products at the current threshold.</p>
        <?php else: ?>
          <div class="table-scroll"><table class="datatable mobile-records">
            <thead><tr><th>Product</th><th class="num">Shop stock</th><th>Last recorded Sale</th><th>Latest recorded Restock</th><th>Advisory</th></tr></thead>
            <tbody><?php foreach ($slowMoving as $product): ?>
              <tr>
                <td data-label="Product" data-record-primary><a href="<?= e(vulcatrack_url('/admin/item-edit.php?id=' . $product['item_id'])) ?>"><?= e($product['item_name']) ?></a></td>
                <td class="num" data-label="Shop stock"><?= $product['stock_quantity'] ?></td>
                <td data-label="Last recorded Sale"><?= $product['last_sale_at'] === null ? 'Never sold' : e((new DateTimeImmutable($product['last_sale_at']))->format('M j, Y g:i A')) ?></td>
                <td data-label="Latest recorded Restock"><?= $product['last_restock_at'] === null ? 'No recorded restock history' : e((new DateTimeImmutable($product['last_restock_at']))->format('M j, Y g:i A')) ?></td>
                <td data-label="Advisory">Review demand, display, promotion, or price.</td>
              </tr>
            <?php endforeach; ?></tbody>
          </table></div>
        <?php endif; ?>
        <p class="panel__foot muted">Never-sold Products enter this list after their item record has existed for <?= $settings['slow_moving_days'] ?> days. Recorded Restock history began with Inventory Foundation; absence of a receipt does not establish past availability.</p>
      <?php else: ?>
        <p class="panel__note muted">Slow-moving results are unavailable.</p>
      <?php endif; ?>
    </section>
  </section>
<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
