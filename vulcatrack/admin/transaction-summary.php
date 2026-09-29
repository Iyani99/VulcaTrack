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
 *
 * Every sale shows its Source: "In-shop", or "Rescue #N" for a sale recorded
 * for a Rescue request (Phase 7.3d) — traceability only; still no payment
 * method or cash data (none is stored). The one-time cash received / change
 * figures stay on the POS "Sale recorded" card, from the session, right after
 * checkout (Decision 54); this page never has them and never shows them.
 *
 * Phase 7.4d layout (presentation only): a screen-only header band, a
 * screen-only Transaction Actions panel (Print, Back to POS, Sales History,
 * View Rescue #N on a Rescue sale) and the receipt-style document itself —
 * the only part that prints (body.is-printdoc + .no-print).
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

/** Stored 'Y-m-d H:i:s' → "Sep 29, 2026 · 4:51 PM" (presentation only), escaped. */
function txn_datetime(string $datetime): string
{
    $t = strtotime($datetime);
    return e($t === false ? $datetime : date('M j, Y · g:i A', $t));
}

/** items.item_type (fixed once sold — Decision 69) → the line's sub-label. */
function txn_kind(?string $type): string
{
    return $type === 'service' ? 'Service' : ($type === 'product' ? 'Product' : '');
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
<?php
$saleNo   = (int) $sale['sale_id'];
$rescueId = $sale['service_request_id'] !== null ? (int) $sale['service_request_id'] : null;
$when     = txn_datetime((string) $sale['sale_date']);
?>
<header class="txn-band no-print">
  <div class="txn-band__main">
    <svg class="txn-band__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
    <div>
      <h1>Transaction Summary <span class="txn-band__no">&middot; Sale #<?= $saleNo ?></span></h1>
      <p class="txn-band__meta">Recorded <?= $when ?> &middot; Cashier: <?= e($sale['admin_name']) ?></p>
    </div>
  </div>
  <p class="txn-band__total"><span>Total</span> <?= txn_peso((int) $sale['total_amount_centavos']) ?></p>
</header>

<div class="txn-layout">
  <aside class="txn-actions no-print" aria-labelledby="txn-actions-h">
    <h2 id="txn-actions-h">Transaction Actions</h2>
    <button type="button" class="btnlink txn-actions__print" onclick="window.print();">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 8H5a3 3 0 0 0-3 3v6h4v4h12v-4h4v-6a3 3 0 0 0-3-3zm-3 11H8v-5h8v5zm3-7a1 1 0 1 1 0-2 1 1 0 0 1 0 2zM18 3H6v4h12z"/></svg>
      Print Summary
    </button>
    <a class="txn-actions__link" href="<?= e(vulcatrack_url('/admin/pos.php')) ?>">&larr; Back to POS</a>
    <a class="txn-actions__link" href="<?= e(vulcatrack_url('/admin/sales.php')) ?>">Sales History</a>
    <?php if ($rescueId !== null): ?>
      <a class="txn-actions__link txn-actions__link--rescue" href="<?= e(vulcatrack_url('/admin/rescue-view.php?id=' . $rescueId)) ?>">View Rescue #<?= $rescueId ?></a>
    <?php endif; ?>
    <p class="txn-actions__note">Only the summary itself is printed.</p>
  </aside>

  <article class="txn-doc">
    <header class="txn-head">
      <p class="txn-brand">
        <svg class="txn-brand__pin" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>
        <span>Vulca<span class="txn-brand__red">Track</span></span>
      </p>
      <p class="txn-shop"><?= e($shop['name']) ?></p>
      <p class="txn-address"><?= e($shop['address']) ?></p>
      <h2 class="txn-title">Transaction Summary</h2>
    </header>

    <dl class="txn-meta">
      <div><dt>Sale no.</dt><dd><?= $saleNo ?></dd></div>
      <div><dt>Date / time</dt><dd><?= $when ?></dd></div>
      <div><dt>Cashier</dt><dd><?= e($sale['admin_name']) ?></dd></div>
      <div><dt>Customer</dt><dd><?= $sale['customer_name'] !== null ? e($sale['customer_name']) : 'Walk-in' ?></dd></div>
      <div><dt>Source</dt><dd><?= $rescueId !== null ? 'Rescue #' . $rescueId : 'In-shop' ?></dd></div>
    </dl>

    <div class="table-scroll">
    <table class="txn-lines">
      <thead>
        <tr><th scope="col">Item / service</th><th scope="col" class="num">Qty</th><th scope="col" class="num">Unit price</th><th scope="col" class="num">Subtotal</th></tr>
      </thead>
      <tbody>
      <?php foreach ($lines as $line): $kind = txn_kind($line['item_type'] ?? null); ?>
        <tr>
          <td><span class="txn-item"><?= e($line['item_name']) ?></span><?php if ($kind !== ''): ?> <span class="txn-kind"><?= $kind ?></span><?php endif; ?></td>
          <td class="num"><?= (int) $line['quantity'] ?></td>
          <td class="num"><?= txn_peso((int) $line['unit_price_centavos']) ?></td>
          <td class="num"><?= txn_peso((int) $line['subtotal_centavos']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <div class="txn-total">
      <p class="txn-total__label">Total</p>
      <p class="txn-total__amount"><?= txn_peso((int) $sale['total_amount_centavos']) ?></p>
    </div>

    <p class="txn-note">For transaction reference only. Not an official BIR invoice.</p>
  </article>
</div>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
