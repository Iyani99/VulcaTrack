<?php
/** Read-only transaction detail. Never look up lines before owner-scoped sale authorization. */

use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Support\Money;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$rawId = $_GET['id'] ?? null;
$saleId = is_string($rawId) && preg_match('/^[1-9]\d*$/D', $rawId) === 1 ? (int) $rawId : 0;
$repository = new SaleRepository(vulcatrack_db());
$sale = $saleId > 0 ? $repository->findForCustomer($saleId, (int) $customer['id']) : null;
$lines = $sale !== null ? $repository->listSaleItems($saleId) : [];

if ($sale === null) {
    http_response_code(404);
}
$pageTitle = $sale === null ? 'Transaction not found' : 'Transaction #' . $saleId;
$navActive = 'purchases';
$mainClass = 'app--wide';
require __DIR__ . '/../src/Views/partials/customer_top.php';
?>
<div class="ph">
  <p class="ph-back"><a href="<?= e(vulcatrack_url('/customer/purchases.php')) ?>">&larr; Purchase History</a></p>
  <?php if ($sale === null): ?>
    <section class="cu-card ph-empty">
      <h1>Transaction not found</h1>
      <p>That transaction is not on your account.</p>
    </section>
  <?php else: ?>
    <?php $when = strtotime((string) $sale['sale_date']); ?>
    <header class="ph-head ph-head--detail">
      <h1>Transaction #<?= (int) $sale['sale_id'] ?></h1>
      <p>Recorded <?= e($when === false ? (string) $sale['sale_date'] : date('M j, Y · g:i A', $when)) ?></p>
    </header>

    <section class="cu-card ph-summary" aria-label="Transaction source and total">
      <div>
        <p class="cu-label">Source</p>
        <?php if ($sale['service_request_id'] === null): ?>
          <p class="ph-summary__value">In-shop</p>
        <?php else: ?>
          <p class="ph-summary__value">On-the-Go Rescue #<?= (int) $sale['service_request_id'] ?></p>
          <p class="ph-summary__note">Request <?= e(ucfirst((string) $sale['rescue_status'])) ?> &middot; <a href="<?= e(vulcatrack_url('/customer/booking.php?id=' . (int) $sale['service_request_id'])) ?>">View booking</a></p>
        <?php endif; ?>
      </div>
      <div class="ph-summary__total">
        <p class="cu-label">Transaction total</p>
        <p class="ph-summary__value">&#8369;<?= e(Money::formatDisplay((int) $sale['total_amount_centavos'])) ?></p>
      </div>
    </section>

    <section class="cu-card ph-items" aria-labelledby="ph-items-title">
      <h2 id="ph-items-title">Products &amp; services</h2>
      <?php if (!$lines): ?>
        <p>Line item details are unavailable for this transaction.</p>
      <?php else: ?>
        <ol class="ph-items__list">
          <?php foreach ($lines as $line): ?>
            <li class="ph-line">
              <div class="ph-line__name">
                <strong><?= e($line['item_name']) ?></strong>
                <span><?= e(ucfirst((string) $line['item_type'])) ?></span>
              </div>
              <div class="ph-line__fact"><span>Qty</span><strong><?= (int) $line['quantity'] ?></strong></div>
              <div class="ph-line__fact"><span>Unit price</span><strong>&#8369;<?= e(Money::formatDisplay((int) $line['unit_price_centavos'])) ?></strong></div>
              <div class="ph-line__fact ph-line__subtotal"><span>Subtotal</span><strong>&#8369;<?= e(Money::formatDisplay((int) $line['subtotal_centavos'])) ?></strong></div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
      <div class="ph-items__total"><span>Total</span><strong>&#8369;<?= e(Money::formatDisplay((int) $sale['total_amount_centavos'])) ?></strong></div>
    </section>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
