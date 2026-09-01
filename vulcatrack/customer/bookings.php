<?php
/**
 * My Bookings -- the customer's rescue-request history (read-only list).
 */

use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Support\OtgStatus;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$requests = (new ServiceRequestRepository(vulcatrack_db()))->listForCustomer((int) $customer['id']);

$pageTitle = 'My Bookings';
$navActive = 'bookings';
require __DIR__ . '/../src/Views/partials/customer_top.php';
?>
<div class="pagehead">
  <h1>My Bookings</h1>
  <a class="btnlink" href="<?= e(vulcatrack_url('/customer/rescue.php')) ?>">Book a Rescue</a>
</div>

<?php if (!$requests): ?>
  <p class="muted">You have not requested any roadside service yet.</p>
<?php else: ?>
  <table class="datatable">
    <thead><tr><th>#</th><th>Requested</th><th>Vehicle</th><th>Status</th><th>ETA snapshot</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($requests as $r): ?>
      <tr>
        <td><?= (int) $r['request_id'] ?></td>
        <td class="muted"><?= e($r['requested_at']) ?></td>
        <td><?= e($r['plate_number']) ?></td>
        <td>
          <span class="badge <?= e(OtgStatus::badgeClass($r['status'])) ?>">
            <?= e(OtgStatus::label($r['status'], $r['tireman_id'] !== null)) ?>
          </span>
        </td>
        <td><?= $r['eta_minutes'] !== null ? (int) $r['eta_minutes'] . ' min' : '—' ?></td>
        <td><a href="<?= e(vulcatrack_url('/customer/booking.php?id=' . (int) $r['request_id'])) ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
