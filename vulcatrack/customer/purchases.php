<?php
/** Read-only purchase and service history for the signed-in customer. */

use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Support\Money;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$sales = (new SaleRepository(vulcatrack_db()))->listForCustomer((int) $customer['id']);

$pageTitle = 'Purchase & Service History';
$navActive = 'purchases';
$mainClass = 'app--wide';
require __DIR__ . '/../src/Views/partials/customer_top.php';
?>
<div class="ph">
  <header class="ph-head">
    <h1>Purchase &amp; Service History</h1>
    <p>Transactions recorded for your customer account, newest first. Rescue bookings are listed separately in <a href="<?= e(vulcatrack_url('/customer/bookings.php')) ?>">My Bookings</a>.</p>
  </header>

  <?php if (!$sales): ?>
    <section class="cu-card ph-empty">
      <h2>No purchases recorded yet</h2>
      <p>Products and services purchased through your customer account will appear here after the shop records a transaction.</p>
    </section>
  <?php else: ?>
    <p class="ph-count"><?= count($sales) ?> transaction<?= count($sales) === 1 ? '' : 's' ?></p>
    <div class="ph-list">
      <?php foreach ($sales as $sale): ?>
        <?php $when = strtotime((string) $sale['sale_date']); ?>
        <article class="cu-card ph-card">
          <div class="ph-card__main">
            <div class="ph-card__top">
              <h2>Transaction #<?= (int) $sale['sale_id'] ?></h2>
              <span class="ph-source"><?= $sale['service_request_id'] === null ? 'In-shop' : 'On-the-Go Rescue #' . (int) $sale['service_request_id'] ?></span>
            </div>
            <p class="ph-card__date"><?= e($when === false ? (string) $sale['sale_date'] : date('M j, Y · g:i A', $when)) ?></p>
          </div>
          <div class="ph-card__end">
            <p class="ph-card__total">&#8369;<?= e(Money::formatDisplay((int) $sale['total_amount_centavos'])) ?></p>
            <a href="<?= e(vulcatrack_url('/customer/purchase.php?id=' . (int) $sale['sale_id'])) ?>">View details<span class="sr-only"> for transaction #<?= (int) $sale['sale_id'] ?></span> &rarr;</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
