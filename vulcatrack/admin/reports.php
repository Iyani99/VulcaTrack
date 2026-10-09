<?php
/**
 * Admin — Sales Reports (Phase 6, Decision 49). READ-ONLY.
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

$today = date('Y-m-d');
$sales = new SaleRepository(vulcatrack_db());
$period = ReportPeriod::resolve($_GET['period'] ?? null, $_GET['month'] ?? null, $today, '30d', true);
$monthOptions = ReportPeriod::monthOptions($sales->listSaleMonths(), $today, $period['month']);
$from = $period['from'];
$to = $period['to'];
$rangeLabel = $period['label'] . ': ' . $from . ($from === $to ? '' : ' to ' . $to);

$summary = $sales->summarize($from, $to);
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
$scale = ReportChart::scale($peak === null ? 0 : $days[$peak]['total_centavos']);
$ticks = $scale['step'] > 0 ? range(0, $scale['max'], $scale['step']) : [0]; // centavos; no sales → just ₱0
// no bar to draw: either no sales at all, or only ₱0 sales (a free service) — never claim "no sales" then
$emptyWindow = $winCount === 0 ? 'No sales in this window' : 'No revenue in this window';

// SVG geometry (px vertically, % horizontally so the plot fills any width).
$chartH   = 230;
$plotTop  = 26;              // headroom for the peak-value label
$baseline = $chartH - 28;    // x-axis labels sit below it
$plotH    = $baseline - $plotTop;
$slot     = 100 / $slotCount;
$barW     = min($slot * 0.62, 3.4); // thin bars (about 24px at desktop widths), never filling the slot
$yOf      = fn (int $c): float => $baseline - ($scale['max'] > 0 ? $c / $scale['max'] * $plotH : 0);

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
    <section class="card card--stat">
      <p class="card__label">Transactions</p>
      <p class="card__num"><?= (int) $summary['count'] ?></p>
    </section>
    <section class="card card--stat card--accent">
      <p class="card__label">Total Sales</p>
      <p class="card__num"><?= reports_peso((int) $summary['total_centavos']) ?></p>
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
    <div class="rpt-plot">
      <svg class="rpt-plot__bars" width="100%" height="<?= $chartH ?>" role="img" aria-labelledby="rpt-svg-title rpt-svg-desc">
        <title id="rpt-svg-title"><?= $period['monthly'] ? 'Monthly' : 'Daily' ?> sales, <?= reports_day($from) ?> to <?= reports_day($to) ?></title>
        <desc id="rpt-svg-desc"><?php if ($peak === null): ?><?= $emptyWindow ?>.<?php else: ?>Total <?= reports_peso($winTotal) ?> from <?= $winCount ?> <?= $winCount === 1 ? 'transaction' : 'transactions' ?>; highest <?= $period['monthly'] ? 'month' : 'day' ?> <?= reports_day($days[$peak]['day'], false, $period['monthly']) ?> at <?= reports_peso($days[$peak]['total_centavos']) ?>. Exact values are in the Daily Sales table.<?php endif; ?></desc>
        <defs><clipPath id="rpt-clip"><rect x="0" y="0" width="100%" height="<?= $baseline ?>"/></clipPath></defs>
        <?php foreach ($ticks as $t): if ($t > 0): ?>
          <line class="rpt-grid" x1="0" x2="100%" y1="<?= round($yOf($t), 1) ?>" y2="<?= round($yOf($t), 1) ?>"/>
        <?php endif; endforeach; ?>
        <line class="rpt-base" x1="0" x2="100%" y1="<?= $baseline ?>" y2="<?= $baseline ?>"/>
        <?php foreach ($days as $i => $d): $c = $d['total_centavos']; $n = $d['transaction_count']; ?>
          <g class="rpt-day" data-day="<?= e($d['day']) ?>" data-centavos="<?= $c ?>">
            <title><?= reports_day($d['day'], false, $period['monthly']) ?>: <?= reports_peso($c) ?> &middot; <?= $n ?> <?= $n === 1 ? 'transaction' : 'transactions' ?></title>
            <rect class="rpt-hit" x="<?= reports_pct($i * $slot) ?>" y="0" width="<?= reports_pct($slot) ?>" height="<?= $baseline ?>"/>
            <?php if ($c > 0): $top = min($yOf($c), $baseline - 2); /* a tiny day still shows 2px above zero */ ?>
              <rect class="rpt-bar" clip-path="url(#rpt-clip)" x="<?= reports_pct(($i + 0.5) * $slot - $barW / 2) ?>" y="<?= round($top, 1) ?>" width="<?= reports_pct($barW) ?>" height="<?= round($baseline - $top + 4, 1) ?>" rx="3"/>
            <?php endif; ?>
          </g>
        <?php endforeach; ?>
        <?php if ($peak !== null):
            $cx = ($peak + 0.5) * $slot;
            $anchor = $cx < 12 ? 'start' : ($cx > 88 ? 'end' : 'middle');
            $lx = $anchor === 'start' ? $peak * $slot : ($anchor === 'end' ? ($peak + 1) * $slot : $cx); ?>
          <text class="rpt-peak" x="<?= reports_pct($lx) ?>" y="<?= round(min($yOf($days[$peak]['total_centavos']), $baseline - 2) - 8, 1) ?>" text-anchor="<?= $anchor ?>"><?= reports_peso($days[$peak]['total_centavos']) ?></text>
        <?php else: ?>
          <text class="rpt-empty" x="50%" y="<?= round($plotTop + $plotH / 2, 1) ?>" text-anchor="middle"><?= $emptyWindow ?></text>
        <?php endif; ?>
        <?php $labels = ReportChart::labelIndexes($slotCount); $lastLabel = end($labels);
        $midLabel = $labels[intdiv(count($labels) - 1, 2)];
        foreach ($labels as $i):
            if ($slotCount === 1) { $anchor = 'middle'; $lx = 50; }
            elseif ($i === 0) { $anchor = 'start'; $lx = 0; }
            elseif ($i === $lastLabel) { $anchor = 'end'; $lx = 100; }
            else { $anchor = 'middle'; $lx = ($i + 0.5) * $slot; }
            // a narrow plot keeps only the first, middle and last label of a long window (CSS container query)
            $minor = $slotCount > 12 && $i !== 0 && $i !== $lastLabel && $i !== $midLabel; ?>
          <text class="rpt-xlabel<?= $minor ? ' rpt-xlabel--minor' : '' ?>" x="<?= reports_pct($lx) ?>" y="<?= $baseline + 19 ?>" text-anchor="<?= $anchor ?>"><?= reports_day($days[$i]['day'], true, $period['monthly']) ?></text>
        <?php endforeach; ?>
      </svg>
      <svg class="rpt-plot__axis" width="100%" height="<?= $chartH ?>" aria-hidden="true">
        <?php foreach ($ticks as $t): ?>
          <text class="rpt-ytick" x="8" y="<?= round($yOf($t) + 4, 1) ?>"><?= reports_axis_peso($t) ?></text>
        <?php endforeach; ?>
      </svg>
    </div>
    <p class="panel__foot muted"><?= $period['monthly'] ? 'Months' : 'Days' ?> without sales are shown as &#8369;0. Hover a <?= $period['monthly'] ? 'month' : 'day' ?> for its total; exact sales dates are in the Daily Sales table.</p>
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
      <p class="panel__note muted">Days without sales are not listed.</p>
      <div class="table-scroll">
      <table class="datatable">
        <thead>
          <tr><th>Date</th><th class="num">Transactions</th><th class="num">Total Sales</th></tr>
        </thead>
        <tbody>
        <?php foreach ($daily as $d): ?>
          <tr>
            <td><?= e($d['day']) ?></td>
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
<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
