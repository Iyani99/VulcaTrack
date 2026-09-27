<?php
/**
 * Admin — one Rescue (OTG) request (Phase 6, Chunk 6.2). READ-ONLY.
 *
 * Shows the request, its customer, vehicle, stored location + frozen ETA
 * (never recomputed — Decision 32), the assigned Tireman and the handling
 * admin if set, and a read-only straight-line map to the shop (Decisions 9/33
 * — no routing, no live tracking). No forms that change anything.
 */

use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Support\OtgStatus;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$shop  = require VULCATRACK_ROOT . '/config/shop.php';

// A positive whole number that fits service_requests.request_id (signed INT); anything else is "not found".
$rawId     = $_GET['id'] ?? '';
$requestId = (is_string($rawId) && preg_match('/^[1-9]\d{0,9}$/', $rawId) && (int) $rawId <= 2147483647)
    ? (int) $rawId
    : 0;
$request = $requestId > 0 ? (new ServiceRequestRepository(vulcatrack_db()))->findForAdmin($requestId) : null;

if ($request === null) {
    http_response_code(404);
    $pageTitle = 'Request not found';
    $navActive = 'rescue';
    require __DIR__ . '/../src/Views/partials/admin_top.php';
    echo '<h1>Request not found</h1><p class="muted">No rescue request has that number.</p>';
    echo '<p><a href="' . e(vulcatrack_url('/admin/rescue.php')) . '">Back to rescue requests</a></p>';
    require __DIR__ . '/../src/Views/partials/admin_bottom.php';
    exit;
}

/** A tel: link for a stored phone number (display text escaped as typed). */
function rescue_tel(string $number): string
{
    return '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $number)) . '">' . e($number) . '</a>';
}

$hasLocation  = $request['latitude'] !== null && $request['longitude'] !== null;
$vehicleBits  = array_filter([$request['make'] ?? '', $request['model'] ?? '', $request['vehicle_type'] ?? '']);

$pageTitle = 'Request #' . (int) $request['request_id'];
$navActive = 'rescue';
$useMap    = $hasLocation;
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <h1>Request #<?= (int) $request['request_id'] ?></h1>
  <span class="badge <?= e(OtgStatus::badgeClass($request['status'])) ?>"><?= e(OtgStatus::adminLabel($request['status'])) ?></span>
</div>
<p class="muted">Read-only view. Status changes and Tireman assignment are not available yet.</p>

<section class="card">
  <h2>Request</h2>
  <dl class="kv">
    <dt>Status</dt><dd><?= e(OtgStatus::adminLabel($request['status'])) ?></dd>
    <dt>Problem</dt><dd><?= nl2br(e($request['problem_description'])) ?></dd>
    <dt>Requested</dt><dd><?= e($request['requested_at']) ?></dd>
    <dt>Last updated</dt><dd><?= e($request['updated_at']) ?></dd>
  </dl>
</section>

<section class="card">
  <h2>Customer</h2>
  <dl class="kv">
    <dt>Name</dt><dd><?= e($request['customer_name']) ?></dd>
    <dt>Contact number</dt><dd><?= rescue_tel((string) $request['customer_contact']) ?></dd>
    <dt>Email</dt><dd><?= e($request['customer_email']) ?></dd>
  </dl>
</section>

<section class="card">
  <h2>Vehicle</h2>
  <dl class="kv">
    <dt>Plate number</dt><dd><?= e($request['plate_number']) ?></dd>
    <dt>Details</dt><dd><?= $vehicleBits ? e(implode(' ', $vehicleBits)) : '<span class="muted">—</span>' ?></dd>
  </dl>
  <?php if ((int) $request['vehicle_active'] !== 1): ?>
    <p class="muted">The customer has since removed this vehicle from their active list.</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Tireman</h2>
  <?php if ($request['tireman_id'] !== null): ?>
    <dl class="kv">
      <dt>Name</dt><dd><?= e($request['tireman_name']) ?><?= (int) $request['tireman_active'] !== 1 ? ' <span class="badge badge--inactive">Inactive</span>' : '' ?></dd>
      <dt>Contact number</dt><dd><?= rescue_tel((string) $request['tireman_contact']) ?></dd>
    </dl>
  <?php else: ?>
    <p class="muted">No Tireman assigned.</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Handled by</h2>
  <?php if ($request['admin_id'] !== null): ?>
    <p><?= e($request['admin_name']) ?></p>
  <?php else: ?>
    <p class="muted">Not yet handled by an admin.</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Location</h2>
  <dl class="kv">
    <dt>ETA (snapshot at request time)</dt>
    <dd><?= $request['eta_minutes'] !== null ? (int) $request['eta_minutes'] . ' minutes' : 'not available' ?>
      <span class="muted">— stored when the customer submitted; it does not change.</span></dd>
    <?php if ($hasLocation): ?>
      <dt>Coordinates</dt>
      <dd><?= e(number_format((float) $request['latitude'], 5)) ?>, <?= e(number_format((float) $request['longitude'], 5)) ?></dd>
    <?php endif; ?>
  </dl>
  <?php if ($hasLocation): ?>
    <div class="mapwrap">
      <div id="otg-map" class="otg-map" data-readonly="1"
           data-shop-lat="<?= e((string) $shop['latitude']) ?>"
           data-shop-lng="<?= e((string) $shop['longitude']) ?>"
           data-shop-name="<?= e($shop['name'] ?? 'Shop') ?>"
           data-cust-lat="<?= e((string) $request['latitude']) ?>"
           data-cust-lng="<?= e((string) $request['longitude']) ?>"
           data-cust-label="Customer location"></div>
    </div>
    <p class="muted">The line is a straight-line reference between the customer and the shop, not a driving route.</p>
    <script src="<?= e(vulcatrack_asset('/assets/js/otg-map.js')) ?>" defer></script>
  <?php else: ?>
    <p class="muted">No location was captured for this request.</p>
  <?php endif; ?>
</section>

<p><a href="<?= e(vulcatrack_url('/admin/rescue.php')) ?>">Back to rescue requests</a></p>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
