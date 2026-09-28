<?php
/**
 * Admin — Sales Reports (Phase 6, Decision 49). READ-ONLY.
 *
 * Aggregated sales for a date range on sales.sale_date (Decision 35): two
 * summary figures (transactions, total sales), a Daily Sales table grouped by
 * sale_date, and an Items Sold table (quantity + revenue from the frozen
 * sale_items.subtotal). The range is the same optional From / To filter as
 * Sales History (admin/sales.php); by default every recorded sale is included.
 * Individual transactions stay on Sales History.
 *
 * Deliberately not here: an item-type column or product/service totals
 * (items.item_type is editable, so it is not a historical fact), walk-in /
 * customer splits, averages, charts, exports, pagination.
 */

use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Support\Money;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();

/** A GET date filter: a valid 'YYYY-MM-DD' day, or null (empty or malformed input is ignored). */
function reports_day_param(string $name): ?string
{
    $value = $_GET[$name] ?? '';
    return (is_string($value) && SaleRepository::isValidDay($value)) ? $value : null;
}

/** Recorded centavos → "₱1234.50" (escaped). */
function reports_peso(int $centavos): string
{
    return '&#8369;' . e(Money::format($centavos));
}

$from = reports_day_param('from');
$to   = reports_day_param('to');
$filtered   = $from !== null || $to !== null;
$rangeError = $from !== null && $to !== null && $from > $to; // same-format days compare as strings

if ($from !== null && $to !== null) {
    $rangeLabel = $from === $to ? $from : $from . ' to ' . $to;
} elseif ($from !== null) {
    $rangeLabel = 'From ' . $from;
} elseif ($to !== null) {
    $rangeLabel = 'Up to ' . $to;
} else {
    $rangeLabel = 'All recorded sales';
}

if (!$rangeError) {
    $sales   = new SaleRepository(vulcatrack_db());
    $summary = $sales->summarize($from, $to);
    $daily   = $sales->listDailyTotals($from, $to);
    $items   = $sales->listItemTotals($from, $to);
    $empty   = $filtered ? 'No sales in this date range.' : 'No sales recorded yet.';
}

$pageTitle = 'Reports';
$navActive = 'reports';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <h1>Sales Reports</h1>
</div>
<p class="muted">Totals from recorded sales, by sale date. Individual transactions are in Sales History.</p>

<form class="filterbar" method="get" action="<?= e(vulcatrack_url('/admin/reports.php')) ?>">
  <label>From
    <input type="date" name="from" value="<?= e($from) ?>">
  </label>
  <label>To
    <input type="date" name="to" value="<?= e($to) ?>">
  </label>
  <button type="submit">Filter</button>
  <?php if ($filtered): ?>
    <a href="<?= e(vulcatrack_url('/admin/reports.php')) ?>">Clear</a>
  <?php endif; ?>
</form>

<?php if ($rangeError): ?>
  <p class="error">The From date cannot be later than the To date.</p>
<?php else: ?>
  <p>Range: <strong><?= e($rangeLabel) ?></strong></p>

  <div class="cardgrid">
    <section class="card">
      <p class="card__label">Transactions</p>
      <p class="card__num"><?= (int) $summary['count'] ?></p>
    </section>
    <section class="card">
      <p class="card__label">Total Sales</p>
      <p class="card__num"><?= reports_peso((int) $summary['total_centavos']) ?></p>
    </section>
  </div>

  <h2>Daily Sales</h2>
  <?php if (!$daily): ?>
    <p class="muted"><?= e($empty) ?></p>
  <?php else: ?>
    <p class="muted">Days without sales are not listed.</p>
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

  <h2>Items Sold</h2>
  <?php if (!$items): ?>
    <p class="muted"><?= e($empty) ?></p>
  <?php else: ?>
    <p class="muted">Revenue uses the price recorded at the time of each sale.</p>
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
<?php endif; ?>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
