<?php
/**
 * Admin — Sales History (Phase 6). READ-ONLY.
 *
 * Every recorded sale, newest first, with an optional From / To date filter on
 * sales.sale_date (Decision 35). By default there is no date restriction — all
 * sales are listed. Each row links to the existing Transaction Summary
 * (admin/transaction-summary.php), which stays the one sale-detail page.
 *
 * Totals are the stored sales.total_amount; nothing is recalculated and the
 * current items.price is never read. There are no forms that change anything
 * here — recorded sales cannot be edited or deleted. No reporting / totals
 * (that is Sales Reports) and no pagination.
 */

use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Support\Money;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();

/** A GET date filter: a valid 'YYYY-MM-DD' day, or null (empty or malformed input is ignored). */
function sales_day_param(string $name): ?string
{
    $value = $_GET[$name] ?? '';
    return (is_string($value) && SaleRepository::isValidDay($value)) ? $value : null;
}

$from = sales_day_param('from');
$to   = sales_day_param('to');
$filtered   = $from !== null || $to !== null;
$rangeError = $from !== null && $to !== null && $from > $to; // same-format days compare as strings

$sales = $rangeError ? [] : (new SaleRepository(vulcatrack_db()))->listForHistory($from, $to);

$pageTitle = 'Sales History';
$navActive = 'sales';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <div>
    <h1>Sales History</h1>
    <p class="pagehead__meta">Recorded sales, newest first. Open a sale to view or print its Transaction Summary.</p>
  </div>
  <form class="filterbar filterbar--head" method="get" action="<?= e(vulcatrack_url('/admin/sales.php')) ?>">
    <label>From
      <input type="date" name="from" value="<?= e($from) ?>">
    </label>
    <label>To
      <input type="date" name="to" value="<?= e($to) ?>">
    </label>
    <button type="submit">Filter</button>
    <?php if ($filtered): ?>
      <a href="<?= e(vulcatrack_url('/admin/sales.php')) ?>">Clear</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($rangeError): ?>
  <p class="error">The From date cannot be later than the To date.</p>
<?php elseif (!$sales): ?>
  <p class="muted"><?= $filtered ? 'No sales in this date range.' : 'No sales recorded yet.' ?></p>
<?php else: ?>
  <?php $n = count($sales); ?>
  <div class="tablepanel">
  <div class="table-scroll">
  <table class="datatable">
    <thead>
      <tr><th>Sale no.</th><th>Date / time</th><th>Cashier</th><th>Customer</th><th class="num">Total</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($sales as $s): ?>
      <tr>
        <td><?= (int) $s['sale_id'] ?></td>
        <td class="muted"><?= e($s['sale_date']) ?></td>
        <td><?= e($s['admin_name']) ?></td>
        <?php /* walk-in vs registered customer: a quiet tag vs a stronger neutral one (presentation only) */ ?>
        <td><?= $s['customer_name'] !== null
            ? '<span class="tag tag--customer">' . e($s['customer_name']) . '</span>'
            : '<span class="tag tag--walkin">Walk-in</span>' ?></td>
        <td class="num cell-money">&#8369;<?= e(Money::format((int) $s['total_amount_centavos'])) ?></td>
        <td class="rowactions">
          <a href="<?= e(vulcatrack_url('/admin/transaction-summary.php?id=' . (int) $s['sale_id'])) ?>">View</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="tablepanel__foot"><?= $n ?> <?= $n === 1 ? 'sale' : 'sales' ?>.</p>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
