<?php
/**
 * Admin — printable Transaction Summary for one recorded sale (Phase 5).
 *
 * This is the printable sale document of Decision 50 ("receipt" is only the
 * working term). It is a transaction reference, NOT an Official Receipt or a
 * BIR-registered invoice, and it carries a note saying so. No TIN / VAT / tax,
 * no receipt table, no payment data — cash tendered / change were never stored
 * (Decision 54) and are not shown here.
 *
 * Historical correctness: quantity, unit price, line subtotal, total and sale
 * date come from the recorded `sales` / `sale_items` rows (unit_price frozen at
 * sale time — Decision 17). Nothing is recalculated and the current
 * `items.price` is never read. The item, cashier and customer NAMES are looked
 * up by id (the approved schema stores no name snapshot), so a later rename
 * shows the current name.
 *
 * Read-only: GET, admin guard, no form handling.
 */

use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Support\Money;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$shop  = require VULCATRACK_ROOT . '/config/shop.php';

/** Recorded centavos → "₱1234.50" (escaped). Formatting only — no arithmetic. */
function txn_peso(int $centavos): string
{
    return '&#8369;' . e(Money::format($centavos));
}

// A positive whole number that fits sales.sale_id (signed INT); anything else is "not found".
$rawId  = $_GET['id'] ?? '';
$saleId = (is_string($rawId) && preg_match('/^[1-9]\d{0,9}$/', $rawId) && (int) $rawId <= 2147483647)
    ? (int) $rawId
    : 0;

$sales = new SaleRepository(vulcatrack_db());
$sale  = $saleId > 0 ? $sales->findSaleForReceipt($saleId) : null;

if ($sale === null) {
    http_response_code(404);
    $pageTitle = 'Sale not found';
    $navActive = 'pos';
    require __DIR__ . '/../src/Views/partials/admin_top.php';
    echo '<h1>Sale not found</h1><p class="muted">No recorded sale has that number.</p>';
    echo '<p><a href="' . e(vulcatrack_url('/admin/pos.php')) . '">Back to POS</a></p>';
    require __DIR__ . '/../src/Views/partials/admin_bottom.php';
    exit;
}

$lines = $sales->listSaleItems($saleId);

$pageTitle = 'Transaction Summary #' . (int) $sale['sale_id'];
$navActive = 'pos';
$bodyClass = 'is-printdoc';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead no-print">
  <a href="<?= e(vulcatrack_url('/admin/pos.php')) ?>">&larr; Back to POS</a>
  <button type="button" class="btnlink" onclick="window.print();">Print</button>
</div>

<article class="card txn-doc">
  <header class="txn-head">
    <p class="txn-shop"><?= e($shop['name']) ?></p>
    <p class="txn-address"><?= e($shop['address']) ?></p>
    <h1>Transaction Summary</h1>
  </header>

  <dl class="txn-meta">
    <div><dt>Sale no.</dt><dd><?= (int) $sale['sale_id'] ?></dd></div>
    <div><dt>Date / time</dt><dd><?= e($sale['sale_date']) ?></dd></div>
    <div><dt>Cashier</dt><dd><?= e($sale['admin_name']) ?></dd></div>
    <div><dt>Customer</dt><dd><?= $sale['customer_name'] !== null ? e($sale['customer_name']) : 'Walk-in' ?></dd></div>
  </dl>

  <div class="table-scroll">
  <table class="datatable txn-lines">
    <thead>
      <tr><th>Item / service</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Subtotal</th></tr>
    </thead>
    <tbody>
    <?php foreach ($lines as $line): ?>
      <tr>
        <td><?= e($line['item_name']) ?></td>
        <td class="num"><?= (int) $line['quantity'] ?></td>
        <td class="num"><?= txn_peso((int) $line['unit_price_centavos']) ?></td>
        <td class="num"><?= txn_peso((int) $line['subtotal_centavos']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="txn-totalrow"><th colspan="3">Total</th><td class="num"><?= txn_peso((int) $sale['total_amount_centavos']) ?></td></tr>
    </tfoot>
  </table>
  </div>

  <p class="txn-note">For transaction reference only. Not an official BIR invoice.</p>
</article>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
