<?php
/**
 * Customer-facing rescue-request status / detail.
 *
 * Shows the frozen ETA snapshot (never recomputed), the route between the two
 * fixed endpoints (redrawn client-side, not persisted), and -- once an admin
 * has assigned one -- the Tireman's name and contact number.
 * ?new=1 shows the post-submission confirmation banner.
 */

use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Support\OtgStatus;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$shop = require VULCATRACK_ROOT . '/config/shop.php';

$requestId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$request = $requestId > 0
    ? (new ServiceRequestRepository(vulcatrack_db()))->findForCustomer($requestId, (int) $customer['id'])
    : null;

$pageTitle = $request ? ('Request #' . $requestId) : 'Request not found';
$navActive = 'bookings';
$useMap = $request !== null;
require __DIR__ . '/../src/Views/partials/customer_top.php';

if ($request === null) {
    http_response_code(404);
    echo '<h1>Request not found</h1>';
    echo '<p class="muted">That request is not on your account.</p>';
    echo '<p><a href="' . e(vulcatrack_url('/customer/bookings.php')) . '">Back to my bookings</a></p>';
    require __DIR__ . '/../src/Views/partials/customer_bottom.php';
    exit;
}

$tiremanAssigned = $request['tireman_id'] !== null;
$hasLocation = $request['latitude'] !== null && $request['longitude'] !== null;
$vehicleBits = array_filter([$request['make'] ?? '', $request['model'] ?? '', $request['vehicle_type'] ?? '']);
?>

<?php if (isset($_GET['new'])): ?>
  <p class="notice">Your rescue request has been submitted. The shop will review it and call you on your contact number.</p>
<?php endif; ?>

<div class="pagehead">
  <h1>Request #<?= (int) $request['request_id'] ?></h1>
  <span class="badge <?= e(OtgStatus::badgeClass($request['status'])) ?>">
    <?= e(OtgStatus::label($request['status'], $tiremanAssigned)) ?>
  </span>
</div>

<section class="card">
  <h2>Details</h2>
  <dl class="kv">
    <dt>Vehicle</dt><dd><?= e($request['plate_number']) ?><?= $vehicleBits ? ' — ' . e(implode(' ', $vehicleBits)) : '' ?></dd>
    <dt>Problem</dt><dd><?= nl2br(e($request['problem_description'])) ?></dd>
    <dt>Submitted</dt><dd><?= e($request['requested_at']) ?></dd>
    <dt>ETA (snapshot at request time)</dt>
    <dd><?= $request['eta_minutes'] !== null ? (int) $request['eta_minutes'] . ' minutes' : 'not available' ?>
      <span class="muted">— this value does not change.</span></dd>
  </dl>
</section>

<?php if ($tiremanAssigned): ?>
  <section class="card">
    <h2>Tireman is on the way</h2>
    <p><strong><?= e($request['tireman_name']) ?></strong></p>
    <p>Contact: <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $request['tireman_contact'])) ?>"><?= e($request['tireman_contact']) ?></a></p>
    <p class="muted">Coordinate directly by phone. There is no in-app messaging or live location.</p>
  </section>
<?php elseif ($request['status'] === 'accepted'): ?>
  <section class="card">
    <p>Your request was accepted. The shop is assigning a tireman &mdash; check back shortly.</p>
  </section>
<?php elseif ($request['status'] === 'rejected'): ?>
  <section class="card">
    <p>The shop was unable to take this request. Please call the shop or submit a new request.</p>
  </section>
<?php elseif ($request['status'] === 'completed'): ?>
  <section class="card">
    <p>This service has been completed. Thank you for using VulcaTrack.</p>
  </section>
<?php else: ?>
  <section class="card">
    <p>Your request is pending review by the shop.</p>
  </section>
<?php endif; ?>

<section class="card">
  <h2>Route</h2>
  <?php if ($hasLocation): ?>
    <div class="mapwrap">
      <div id="otg-map" class="otg-map" data-readonly="1"
           data-shop-lat="<?= e((string) $shop['latitude']) ?>"
           data-shop-lng="<?= e((string) $shop['longitude']) ?>"
           data-shop-name="<?= e($shop['name'] ?? 'Shop') ?>"
           data-cust-lat="<?= e((string) $request['latitude']) ?>"
           data-cust-lng="<?= e((string) $request['longitude']) ?>"></div>
    </div>
    <p class="muted">
      Your location: <?= e(number_format((float) $request['latitude'], 5)) ?>,
      <?= e(number_format((float) $request['longitude'], 5)) ?> &middot;
      Shop: <?= e($shop['name'] ?? 'VulcaTrack') ?>.
      The line is a straight-line reference, not a driving route.
    </p>
    <script src="<?= e(vulcatrack_url('/assets/js/otg-map.js')) ?>" defer></script>
  <?php else: ?>
    <p class="muted">No location was captured for this request.</p>
  <?php endif; ?>
</section>

<p><a href="<?= e(vulcatrack_url('/customer/bookings.php')) ?>">Back to my bookings</a></p>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
