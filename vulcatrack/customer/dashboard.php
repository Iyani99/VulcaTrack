<?php
/**
 * Customer dashboard / home.
 */

use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Repository\VehicleRepository;
use VulcaTrack\Support\OtgStatus;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$pdo = vulcatrack_db();

$vehicles = new VehicleRepository($pdo);
$requests = new ServiceRequestRepository($pdo);

$activeVehicles = $vehicles->countActiveForCustomer((int) $customer['id']);
$openRequests   = $requests->countOpenForCustomer((int) $customer['id']);
$latest         = $requests->latestForCustomer((int) $customer['id']);

$pageTitle = 'Dashboard';
$navActive = 'dashboard';
require __DIR__ . '/../src/Views/partials/customer_top.php';
?>
<h1>Hello, <?= e($customer['name']) ?></h1>

<div class="cardgrid">
  <section class="card">
    <p class="card__num"><?= (int) $activeVehicles ?></p>
    <p class="card__label">Saved vehicle<?= $activeVehicles === 1 ? '' : 's' ?></p>
    <p><a href="<?= e(vulcatrack_url('/customer/vehicles.php')) ?>">Manage vehicles</a></p>
  </section>

  <section class="card">
    <p class="card__num"><?= (int) $openRequests ?></p>
    <p class="card__label">Open rescue request<?= $openRequests === 1 ? '' : 's' ?></p>
    <p><a href="<?= e(vulcatrack_url('/customer/bookings.php')) ?>">View my bookings</a></p>
  </section>

  <section class="card card--cta">
    <p class="card__label">Need roadside help?</p>
    <p><a class="btnlink" href="<?= e(vulcatrack_url('/customer/rescue.php')) ?>">Book a Rescue</a></p>
  </section>
</div>

<?php if ($latest !== null): ?>
  <section class="card">
    <h2>Latest request</h2>
    <p>
      <span class="badge <?= e(OtgStatus::badgeClass($latest['status'])) ?>">
        <?= e(OtgStatus::label($latest['status'], $latest['tireman_id'] !== null)) ?>
      </span>
    </p>
    <p class="muted">
      Vehicle <?= e($latest['plate_number']) ?> &middot;
      submitted <?= e($latest['requested_at']) ?> &middot;
      ETA snapshot <?= $latest['eta_minutes'] !== null ? (int) $latest['eta_minutes'] . ' min' : '—' ?>
    </p>
    <p><a href="<?= e(vulcatrack_url('/customer/booking.php?id=' . (int) $latest['request_id'])) ?>">Open request #<?= (int) $latest['request_id'] ?></a></p>
  </section>
<?php endif; ?>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
