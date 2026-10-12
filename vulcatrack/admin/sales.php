<?php
/** Admin Sales History: read-only operational transaction finding. */

use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Support\Money;
use VulcaTrack\Support\SalesHistoryFilter;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$filter = SalesHistoryFilter::resolve($_GET);
$summary = ['count' => 0, 'total_centavos' => 0, 'in_shop_count' => 0, 'rescue_count' => 0];
$sales = [];
$page = $pages = 1;
if ($filter['errors'] === []) {
    $repo = new SaleRepository(vulcatrack_db());
    $from = $filter['from'] === '' ? null : $filter['from'];
    $to = $filter['to'] === '' ? null : $filter['to'];
    $summary = $repo->summarizeHistory($from, $to, $filter['source']);
    $pages = max(1, (int) ceil($summary['count'] / SalesHistoryFilter::PAGE_SIZE));
    $page = min($filter['page'], $pages);
    if ($summary['count'] > 0) {
        $sales = $repo->listHistoryPage($from, $to, $filter['source'], $filter['sort'],
            SalesHistoryFilter::PAGE_SIZE, ($page - 1) * SalesHistoryFilter::PAGE_SIZE);
    }
}
function sales_history_url(array $filter, ?int $page = null): string
{
    return vulcatrack_url('/admin/sales.php') . '?' . http_build_query(SalesHistoryFilter::parameters($filter, $page));
}
function sales_history_day(string $day): string
{
    return SaleRepository::isValidDay($day)
        ? (new DateTimeImmutable($day, new DateTimeZone('Asia/Manila')))->format('F j, Y')
        : '';
}
$displayFrom = sales_history_day($filter['from']);
$displayTo = sales_history_day($filter['to']);
$displayRange = $displayFrom !== '' && $displayTo !== ''
    ? ($displayFrom === $displayTo ? $displayFrom : $displayFrom . ' to ' . $displayTo)
    : ($displayFrom !== '' ? 'From ' . $displayFrom : ($displayTo !== '' ? 'Through ' . $displayTo : 'Choose a date'));
$fallbackDay = SaleRepository::isValidDay($filter['day']) ? $filter['day']
    : (SaleRepository::isValidDay($filter['from']) ? $filter['from']
        : (SaleRepository::isValidDay($filter['to']) ? $filter['to']
            : (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d')));
$exactLink = $filter;
$exactLink['period'] = 'exact';
$exactLink['day'] = $fallbackDay;
$customLink = $filter;
$customLink['period'] = 'custom';
$customLink['from'] = SaleRepository::isValidDay($filter['from']) ? $filter['from'] : $fallbackDay;
$customLink['to'] = SaleRepository::isValidDay($filter['to']) ? $filter['to'] : $fallbackDay;
if ($customLink['from'] > $customLink['to']) {
    $customLink['from'] = $customLink['to'] = $fallbackDay;
}
$showReset = $filter['period'] !== 'today' || $filter['source'] !== 'all'
    || $filter['sort'] !== 'newest' || $filter['errors'] !== [];
$pageTitle = 'Sales History';
$navActive = 'sales';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <div>
    <h1>Sales History</h1>
    <p class="pagehead__meta">Find recorded transactions. Open a sale to view or print its Transaction Summary.</p>
  </div>
</div>
<section class="sales-history" aria-label="Sales History filters" data-open="<?= in_array($filter['period'], ['exact', 'custom'], true) ? e($filter['period']) : '' ?>" data-legacy-open="<?= $filter['period'] === 'custom' && (($filter['from'] === '') !== ($filter['to'] === '')) ? 'true' : 'false' ?>">
  <p class="sales-history__period"><strong><?= e($filter['label']) ?></strong> <span><?= e($displayRange) ?></span></p>
  <nav class="sales-history__quick" aria-label="Quick periods">
    <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => '7 Days', '30d' => '30 Days', 'exact' => 'Exact Day', 'custom' => 'Custom'] as $mode => $label): ?>
      <?php $quick = $filter; $quick['period'] = $mode; ?>
      <a href="<?= e($mode === 'exact' ? sales_history_url($exactLink) : ($mode === 'custom' ? sales_history_url($customLink) : sales_history_url($quick))) ?>" data-sales-period="<?= e($mode) ?>" class="<?= $filter['period'] === $mode ? 'is-active' : '' ?>"<?= $filter['period'] === $mode ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="sales-history__dates sales-history__dates--exact" method="get" action="<?= e(vulcatrack_url('/admin/sales.php')) ?>">
    <input type="hidden" name="period" value="exact">
    <input type="hidden" name="source" value="<?= e($filter['source']) ?>">
    <input type="hidden" name="sort" value="<?= e($filter['sort']) ?>">
    <label>Exact day <input type="date" name="day" value="<?= e($filter['period'] === 'exact' ? $filter['day'] : '') ?>" required></label>
    <button type="submit" class="sales-history__fallback-submit">Show day</button>
  </form>
  <form class="sales-history__dates sales-history__dates--custom" method="get" action="<?= e(vulcatrack_url('/admin/sales.php')) ?>">
    <input type="hidden" name="period" value="custom">
    <input type="hidden" name="source" value="<?= e($filter['source']) ?>">
    <input type="hidden" name="sort" value="<?= e($filter['sort']) ?>">
    <label>From <input type="date" name="from" value="<?= e($filter['period'] === 'custom' ? $filter['from'] : '') ?>"></label>
    <label>To <input type="date" name="to" value="<?= e($filter['period'] === 'custom' ? $filter['to'] : '') ?>"></label>
    <button type="submit" class="sales-history__fallback-submit">Show range</button>
  </form>
  <form class="sales-history__filters" method="get" action="<?= e(vulcatrack_url('/admin/sales.php')) ?>">
    <input type="hidden" name="period" value="<?= e($filter['period']) ?>">
    <?php if ($filter['period'] === 'exact'): ?><input type="hidden" name="day" value="<?= e($filter['day']) ?>"><?php endif; ?>
    <?php if ($filter['period'] === 'custom'): ?>
      <?php if ($filter['from'] !== ''): ?><input type="hidden" name="from" value="<?= e($filter['from']) ?>"><?php endif; ?>
      <?php if ($filter['to'] !== ''): ?><input type="hidden" name="to" value="<?= e($filter['to']) ?>"><?php endif; ?>
    <?php endif; ?>
    <label>Source <select name="source">
      <?php foreach (['all' => 'All Sources', 'in_shop' => 'In-shop', 'rescue' => 'Rescue-linked'] as $value => $label): ?>
        <option value="<?= e($value) ?>"<?= $filter['source'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select></label>
    <label>Sort <select name="sort">
      <?php foreach (['newest' => 'Newest', 'oldest' => 'Oldest', 'highest' => 'Highest Total', 'lowest' => 'Lowest Total'] as $value => $label): ?>
        <option value="<?= e($value) ?>"<?= $filter['sort'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select></label>
    <button type="submit" class="sales-history__fallback-submit">Show sales</button>
  </form>
  <?php if ($showReset): ?><a class="sales-history__reset" href="<?= e(vulcatrack_url('/admin/sales.php')) ?>">Reset to Today</a><?php endif; ?>
</section>
<?php if ($filter['errors'] !== []): ?>
  <div class="error" role="alert"><?php foreach ($filter['errors'] as $error): ?><p><?= e($error) ?></p><?php endforeach; ?></div>
<?php else: ?>
  <section class="sales-history__summary" aria-label="Selected result summary">
    <p class="sales-history__total">Total Sales: <strong>&#8369;<?= e(Money::formatDisplay($summary['total_centavos'])) ?></strong></p>
    <p><?= number_format($summary['count']) ?> <?= $summary['count'] === 1 ? 'transaction' : 'transactions' ?></p>
    <?php if ($filter['source'] === 'all'): ?><p class="sales-history__split"><?= number_format($summary['in_shop_count']) ?> In-shop &middot; <?= number_format($summary['rescue_count']) ?> Rescue-linked</p><?php endif; ?>
  </section>
  <?php if ($summary['count'] === 0): ?>
    <p class="sales-history__empty"><?= $filter['source'] !== 'all' ? 'No matches for the selected source.' : ($filter['period'] === 'today' ? 'No sales today.' : 'No sales in the selected range.') ?></p>
  <?php else: ?>
    <p class="sales-history__page-context">Showing <?= number_format(($page - 1) * SalesHistoryFilter::PAGE_SIZE + 1) ?>–<?= number_format(min($page * SalesHistoryFilter::PAGE_SIZE, $summary['count'])) ?> of <?= number_format($summary['count']) ?> <?= $summary['count'] === 1 ? 'transaction' : 'transactions' ?> · Page <?= $page ?> of <?= $pages ?></p>
    <div class="tablepanel sales-history__table"><div class="table-scroll"><table class="datatable mobile-records">
      <thead><tr><th>Sale no.</th><th>Date / time</th><th>Cashier</th><th>Customer</th><th>Source</th><th class="num">Total</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sales as $s): ?>
        <tr>
          <td data-label="Sale" data-record-primary><span class="mobile-record-prefix" aria-hidden="true">#</span><?= (int) $s['sale_id'] ?></td>
          <td class="muted nowrap" data-label="Date / time"><?= e($s['sale_date']) ?></td>
          <td data-label="Cashier"><?= e($s['admin_name']) ?></td>
          <td data-label="Customer"><?= $s['customer_name'] !== null
              ? '<span class="tag tag--customer">' . e($s['customer_name']) . '</span>'
              : '<span class="tag tag--walkin">Walk-in</span>' ?></td>
          <td data-label="Source"><?= $s['service_request_id'] !== null
              ? '<a class="tag tag--rescue" href="' . e(vulcatrack_url('/admin/rescue-view.php?id=' . (int) $s['service_request_id'])) . '">Rescue #' . (int) $s['service_request_id'] . '</a>'
              : '<span class="tag tag--inshop">In-shop</span>' ?></td>
          <td class="num cell-money" data-label="Total">&#8369;<?= e(Money::formatDisplay((int) $s['total_amount_centavos'])) ?></td>
          <td class="rowactions" data-label="Action" data-record-actions><a href="<?= e(vulcatrack_url('/admin/transaction-summary.php?id=' . (int) $s['sale_id'])) ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div></div>
    <?php if ($pages > 1): ?>
      <nav class="sales-history__pagination" aria-label="Sales pages">
        <?php if ($page > 1): ?><a href="<?= e(sales_history_url($filter, $page - 1)) ?>">&larr; Previous</a><?php endif; ?>
        <span>Page <?= $page ?> of <?= $pages ?></span>
        <?php if ($page < $pages): ?><a href="<?= e(sales_history_url($filter, $page + 1)) ?>">Next &rarr;</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<script src="<?= e(vulcatrack_asset('/assets/js/sales-history.js')) ?>" defer></script>
<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
