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
require __DIR__ . '/../src/Views/partials/customer_top.php';
?>
<h1>Book a Rescue</h1>

<?php if (!$vehicles): ?>
  <section class="card">
    <p>You need an active vehicle before you can request roadside service.</p>
    <p><a class="btnlink" href="<?= e(vulcatrack_url('/customer/vehicle-edit.php')) ?>">Add a vehicle</a></p>
  </section>
<?php else: ?>

  <?php if (!empty($errors['form'])): ?><p class="error"><?= e($errors['form']) ?></p><?php endif; ?>

  <form method="post" action="<?= e(vulcatrack_url('/customer/rescue.php')) ?>" novalidate>
    <?= Csrf::field() ?>

    <section class="card">
      <h2>1. Which vehicle?</h2>
      <label for="vehicle_id">Vehicle</label>
      <select id="vehicle_id" name="vehicle_id" required>
        <option value="">— choose a vehicle —</option>
        <?php foreach ($vehicles as $veh): ?>
          <option value="<?= (int) $veh['vehicle_id'] ?>" <?= (string) $old['vehicle_id'] === (string) $veh['vehicle_id'] ? 'selected' : '' ?>>
            <?= e($veh['plate_number']) ?><?php
              $d = array_filter([$veh['make'] ?? '', $veh['model'] ?? '', $veh['vehicle_type'] ?? '']);
              echo $d ? ' — ' . e(implode(' ', $d)) : ''; ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($errors['vehicle_id'])): ?><small class="error"><?= e($errors['vehicle_id']) ?></small><?php endif; ?>
    </section>

    <section class="card">
      <h2>2. What's wrong?</h2>
      <label for="problem_description">Describe the problem</label>
      <textarea id="problem_description" name="problem_description" rows="3" maxlength="2000" required><?= e($old['problem_description']) ?></textarea>
      <?php if (!empty($errors['problem_description'])): ?><small class="error"><?= e($errors['problem_description']) ?></small><?php endif; ?>
    </section>

    <section class="card">
      <h2>3. Where are you?</h2>
      <p class="muted">We use your location once, to work out the route and a one-time ETA. We do not track you.</p>

      <div class="mapwrap">
        <div id="otg-map" class="otg-map"
             data-shop-lat="<?= e((string) $shop['latitude']) ?>"
             data-shop-lng="<?= e((string) $shop['longitude']) ?>"
             data-shop-name="<?= e($shop['name'] ?? 'Shop') ?>"></div>
      </div>

      <p>
        <button type="button" id="otg-locate" class="secondary">Use my current location</button>
      </p>
      <p id="otg-loc-status" class="loc-status">Location not set yet.</p>
      <?php if (!empty($errors['location'])): ?><small class="error"><?= e($errors['location']) ?></small><?php endif; ?>

      <input type="hidden" id="otg-lat" name="latitude" value="<?= e($old['latitude']) ?>">
      <input type="hidden" id="otg-lng" name="longitude" value="<?= e($old['longitude']) ?>">

      <details class="manual-coords">
        <summary>Enter coordinates manually</summary>
        <label for="otg-lat-manual">Latitude</label>
        <input type="text" id="otg-lat-manual" inputmode="decimal" placeholder="e.g. 10.31570">
        <label for="otg-lng-manual">Longitude</label>
        <input type="text" id="otg-lng-manual" inputmode="decimal" placeholder="e.g. 123.88540">
        <button type="button" id="otg-apply-manual" class="secondary">Apply coordinates</button>
      </details>
    </section>

    <section class="card">
      <h2>4. Confirm</h2>
      <p class="muted">
        Shop: <?= e($shop['name'] ?? 'VulcaTrack') ?><?= isset($shop['address']) ? ' — ' . e($shop['address']) : '' ?>.<br>
        Contact number on file: <strong><?= e($record['contact_number']) ?></strong>
        (<a href="<?= e(vulcatrack_url('/customer/profile.php')) ?>">update</a>).
        The shop will call you on this number.
      </p>
      <button type="submit">Submit rescue request</button>
    </section>
  </form>

  <script src="<?= e(vulcatrack_url('/assets/js/otg-map.js')) ?>" defer></script>
<?php endif; ?>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
