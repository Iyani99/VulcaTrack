<?php
/**
 * Book a Rescue -- On-the-Go service request submission.
 *
 * Requires an authenticated customer (Decision 1). Uses the customer's saved
 * ACTIVE vehicle and their mandatory contact number (Decision 2). Location is
 * captured client-side (browser geolocation / map pin) and stored as
 * latitude/longitude (Decision 3). ETA is computed ONCE here and frozen
 * (Decisions 5/6/32). No live tracking, no polyline persisted.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Repository\VehicleRepository;
use VulcaTrack\Support\Geo;
use VulcaTrack\Support\Validator;
use VulcaTrack\Support\VehicleType;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$config = $GLOBALS['vulcatrack_config'];
$shop = require VULCATRACK_ROOT . '/config/shop.php';
$pdo = vulcatrack_db();

$customerRepo = new CustomerRepository($pdo);
$vehicleRepo = new VehicleRepository($pdo);
$requestRepo = new ServiceRequestRepository($pdo);

$record = $customerRepo->findById((int) $customer['id']);
if ($record === null) {
    vulcatrack_auth()->logout();
    header('Location: ' . vulcatrack_url('/login.php'));
    exit;
}

// Contact number is mandatory for OTG coordination.
if (trim((string) $record['contact_number']) === '') {
    header('Location: ' . vulcatrack_url('/customer/profile.php'));
    exit;
}

$vehicles = $vehicleRepo->listForCustomer((int) $customer['id'], false);

$errors = [];
$old = ['vehicle_id' => '', 'problem_description' => '', 'latitude' => '', 'longitude' => ''];

if ($vehicles && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $v = new Validator();

        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        $vehicle = $vehicleId > 0 ? $vehicleRepo->findForCustomer($vehicleId, (int) $customer['id']) : null;
        if ($vehicle === null || (int) $vehicle['is_active'] !== 1) {
            $v->add('vehicle_id', 'Choose one of your active vehicles.');
        }

        $problem = $v->text('problem_description', $_POST['problem_description'] ?? null, 'Problem description', 2000, 5);
        $coords = $v->coordinates('location', $_POST['latitude'] ?? null, $_POST['longitude'] ?? null);

        $old = [
            'vehicle_id'          => $vehicleId ?: '',
            'problem_description' => (string) ($_POST['problem_description'] ?? ''),
            'latitude'            => (string) ($_POST['latitude'] ?? ''),
            'longitude'           => (string) ($_POST['longitude'] ?? ''),
        ];

        if ($v->passes()) {
            [$lat, $lng] = $coords;
            $distanceKm = Geo::haversineKm(
                $lat, $lng,
                (float) $shop['latitude'], (float) $shop['longitude']
            );
            $eta = Geo::etaMinutes(
                $distanceKm,
                (float) ($config['otg']['average_speed_kmph'] ?? 25),
                (int) ($config['otg']['min_eta_minutes'] ?? 5)
            );

            $requestId = $requestRepo->createPending(
                (int) $customer['id'], $vehicleId, $problem, $lat, $lng, $eta
            );

            header('Location: ' . vulcatrack_url('/customer/booking.php?id=' . $requestId . '&new=1'));
            exit;
        }
        $errors = $v->errors();
    }
}

$pageTitle = 'Book a Rescue';
$navActive = 'rescue';
$useMap = true;
$mainClass = 'app--wide';
require __DIR__ . '/../src/Views/partials/customer_top.php';
?>
<div class="rb">
<div class="rb-head">
  <p class="rb-eyebrow"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M4.2 7.5l15.6 9M4.2 16.5l15.6-9"/></svg>Roadside rescue</p>
  <h1>Request Rescue Service</h1>
  <p class="rb-sub">Follow the steps below. The shop reviews every request and calls you on your contact number.</p>
</div>

<?php if (!$vehicles): ?>
  <section class="cu-card rb-step">
    <h2 class="rb-step__title"><span class="rb-step__num">1</span>Select vehicle</h2>
    <p>You need an active vehicle before you can request roadside service.</p>
    <p class="rb-actions"><a class="cu-btn cu-btn--red" href="<?= e(vulcatrack_url('/customer/vehicle-edit.php')) ?>">Add a vehicle</a></p>
  </section>
<?php else: ?>

  <?php if (!empty($errors['form'])): ?>
    <p class="cu-alert" role="alert"><?= e($errors['form']) ?></p>
  <?php elseif ($errors): ?>
    <p class="cu-alert" role="alert">Please check the highlighted steps below.</p>
  <?php endif; ?>

  <form method="post" action="<?= e(vulcatrack_url('/customer/rescue.php')) ?>" novalidate>
    <?= Csrf::field() ?>

    <fieldset class="cu-card rb-step<?= !empty($errors['vehicle_id']) ? ' rb-step--error' : '' ?>"<?= !empty($errors['vehicle_id']) ? ' aria-describedby="err-vehicle"' : '' ?>>
      <legend class="rb-step__title"><span class="rb-step__num">1</span>Select vehicle</legend>
      <div class="rb-vehicles">
        <?php foreach ($vehicles as $veh): ?>
          <?php $details = array_filter([$veh['make'] ?? '', $veh['model'] ?? '']); ?>
          <label class="rb-vehicle">
            <input type="radio" name="vehicle_id" value="<?= (int) $veh['vehicle_id'] ?>" required<?= (string) $old['vehicle_id'] === (string) $veh['vehicle_id'] ? ' checked' : '' ?>>
            <span class="rb-vehicle__art"><?= VehicleType::icon($veh['vehicle_type'] ?? null) ?></span>
            <span class="rb-vehicle__name"><?= $details ? e(implode(' ', $details)) : e($veh['vehicle_type'] ?: 'Vehicle') ?></span>
            <span class="rb-vehicle__plate">Plate # <?= e($veh['plate_number']) ?><?= $details && !empty($veh['vehicle_type']) ? ' &middot; ' . e($veh['vehicle_type']) : '' ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($errors['vehicle_id'])): ?><small class="error" id="err-vehicle"><?= e($errors['vehicle_id']) ?></small><?php endif; ?>
      <p class="rb-note">Not listed? <a href="<?= e(vulcatrack_url('/customer/vehicles.php')) ?>">Manage my vehicles</a></p>
    </fieldset>

    <section class="cu-card rb-step<?= !empty($errors['problem_description']) ? ' rb-step--error' : '' ?>">
      <h2 class="rb-step__title"><span class="rb-step__num">2</span>Describe the issue</h2>
      <label class="sr-only" for="problem_description">Describe the problem</label>
      <textarea id="problem_description" name="problem_description" rows="4" maxlength="2000" required
                placeholder="What happened? e.g. flat rear tire, the car can't move, parked beside the public market."<?= !empty($errors['problem_description']) ? ' aria-invalid="true" aria-describedby="err-problem"' : '' ?>><?= e($old['problem_description']) ?></textarea>
      <?php if (!empty($errors['problem_description'])): ?><small class="error" id="err-problem"><?= e($errors['problem_description']) ?></small><?php endif; ?>
    </section>

    <section class="cu-card rb-step<?= !empty($errors['location']) ? ' rb-step--error' : '' ?>">
      <h2 class="rb-step__title"><span class="rb-step__num">3</span>Confirm location</h2>

      <div class="mapwrap">
        <div id="otg-map" class="otg-map"
             data-shop-lat="<?= e((string) $shop['latitude']) ?>"
             data-shop-lng="<?= e((string) $shop['longitude']) ?>"
             data-shop-name="<?= e($shop['name'] ?? 'Shop') ?>"
             data-geocode-url="<?= e(vulcatrack_url('/customer/geocode.php')) ?>"></div>
      </div>

      <div class="rb-locate">
        <label class="sr-only" for="otg-search-q">Search a landmark or address</label>
        <div class="loc-search">
          <input type="text" id="otg-search-q" name="otg_search_q" maxlength="120" autocomplete="off"
                 placeholder="Search a landmark or address, e.g. SM City Baliwag"
                 onkeydown="if(event.key==='Enter'){event.preventDefault();}">
          <button type="button" id="otg-search-btn" class="secondary">Search</button>
        </div>
        <button type="button" id="otg-locate" class="secondary rb-locate__me"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2.5"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>Locate me</button>
      </div>
      <p id="otg-search-status" class="loc-status" hidden></p>
      <ul id="otg-search-results" class="loc-results" hidden></ul>
      <p class="loc-attribution muted">Search results from OpenStreetMap / Nominatim.</p>

      <p id="otg-loc-status" class="loc-status">Location not set yet.</p>
      <p id="otg-selected-label" class="loc-selected" hidden></p>
      <p class="rb-note">Use <strong>Locate me</strong> (when your device allows it) or search, then drag the pin to the exact roadside spot. The pin's final position is what we save.</p>

      <?php if (!empty($errors['location'])): ?><small class="error"><?= e($errors['location']) ?></small><?php endif; ?>

      <input type="hidden" id="otg-lat" name="latitude" value="<?= e($old['latitude']) ?>">
      <input type="hidden" id="otg-lng" name="longitude" value="<?= e($old['longitude']) ?>">

      <details class="manual-coords">
        <summary>Enter coordinates manually</summary>
        <label for="otg-lat-manual">Latitude</label>
        <input type="text" id="otg-lat-manual" inputmode="decimal" placeholder="e.g. 14.95120">
        <label for="otg-lng-manual">Longitude</label>
        <input type="text" id="otg-lng-manual" inputmode="decimal" placeholder="e.g. 120.89810">
        <button type="button" id="otg-apply-manual" class="secondary">Apply coordinates</button>
      </details>
    </section>

    <aside class="rb-privacy" aria-label="Location privacy">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.5v.5"/></svg>
      <div>
        <p class="rb-privacy__title">Privacy notice</p>
        <p>We use this location once, to work out the route and a one-time ETA. We do not track you.</p>
      </div>
    </aside>

    <section class="cu-card rb-review" aria-labelledby="rb-review-title">
      <h2 class="rb-step__title" id="rb-review-title"><span class="rb-step__num">4</span>Before you submit</h2>
      <dl class="rb-review__list">
        <div><dt>Contact number on file</dt><dd><strong><?= e($record['contact_number']) ?></strong> <a href="<?= e(vulcatrack_url('/customer/profile.php')) ?>">Update</a><span class="rb-review__hint">The shop will call you on this number.</span></dd></div>
        <div><dt>Shop</dt><dd><?= e($shop['name'] ?? 'VulcaTrack') ?><?= isset($shop['address']) ? '<span class="rb-review__hint">' . e($shop['address']) . '</span>' : '' ?></dd></div>
        <div><dt>Charges</dt><dd>Nothing is charged here.<span class="rb-review__hint">After the job, the shop records the products and services actually used.</span></dd></div>
      </dl>
    </section>

    <button type="submit" class="rb-submit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 21 3l-6.5 18-3-7.5z"/></svg>Submit rescue request</button>
  </form>

  <script src="<?= e(vulcatrack_asset('/assets/js/otg-map.js')) ?>" defer></script>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
