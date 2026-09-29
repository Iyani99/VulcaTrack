<?php
/**
 * My Bookings -- the customer's rescue-request history (read-only list).
 * Phase 7.4b-d: follows the Booking History Figma on the .cust system.
 *
 * Only the signed-in customer's own requests (owner-scoped query), newest
 * first. Figma differences, on purpose: no Amount / payment column (a rescue
 * is not priced — any sale is recorded at the shop after the job), no status
 * filter (none exists), references are the real request numbers, and the row
 * action opens the status page (no live tracking).
 */

use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Support\OtgStatus;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$requests = (new ServiceRequestRepository(vulcatrack_db()))->listForCustomer((int) $customer['id']);

/** "Sep 28, 2026 · 1:57 PM" from a stored DATETIME (display only). */
function bookings_when(string $datetime): string
{
    $t = strtotime($datetime);
    return $t === false ? $datetime : date('M j, Y · g:i A', $t);
}

$pageTitle = 'My Bookings';
$navActive = 'bookings';
$mainClass = 'app--wide';
require __DIR__ . '/../src/Views/partials/customer_top.php';
?>
<div class="bk-head">
  <div>
    <h1>My Bookings</h1>
    <p class="bk-sub">Your rescue requests, newest first. Open one to see its status.</p>
  </div>
  <a class="cu-btn cu-btn--red" href="<?= e(vulcatrack_url('/customer/rescue.php')) ?>">Book a Rescue</a>
</div>

<?php if (!$requests): ?>
  <section class="cu-card bk-empty">
    <p class="bk-empty__title">No rescue requests yet</p>
    <p>When you book a rescue, it appears here with its status.</p>
    <p><a class="cu-btn cu-btn--outline" href="<?= e(vulcatrack_url('/customer/rescue.php')) ?>">Book a Rescue</a></p>
  </section>
<?php else: ?>
  <div class="table-scroll">
  <table class="cu-table cu-table--wide bk-table">
    <thead><tr><th>Request #</th><th>Date &amp; time</th><th>Vehicle</th><th>Status</th><th>ETA at request</th><th><span class="sr-only">Details</span></th></tr></thead>
    <tbody>
    <?php foreach ($requests as $r): ?>
      <?php $vehicle = trim(implode(' ', array_filter([$r['make'] ?? '', $r['model'] ?? '']))); ?>
      <tr class="bk-row--<?= e($r['status']) ?>">
        <td class="nowrap bk-ref">#<?= (int) $r['request_id'] ?></td>
        <td class="nowrap"><?= e(bookings_when((string) $r['requested_at'])) ?></td>
        <td><?= $vehicle !== '' ? e($vehicle) . ' &middot; ' : '' ?><span class="nowrap"><?= e($r['plate_number']) ?></span></td>
        <td><span class="cu-status cu-status--<?= e($r['status']) ?>"><?= e(OtgStatus::label($r['status'], $r['tireman_id'] !== null)) ?></span></td>
        <td class="nowrap bk-eta"><?= $r['eta_minutes'] !== null ? (int) $r['eta_minutes'] . ' mins' : '—' ?></td>
        <td class="cu-table__action"><a href="<?= e(vulcatrack_url('/customer/booking.php?id=' . (int) $r['request_id'])) ?>">View<span class="sr-only"> request #<?= (int) $r['request_id'] ?></span></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="bk-note"><?= count($requests) ?> request<?= count($requests) === 1 ? '' : 's' ?>. The ETA is the estimate calculated when each request was submitted; it does not update.</p>
<?php endif; ?>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
