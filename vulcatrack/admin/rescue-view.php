<?php
/**
 * Admin — one Rescue (OTG) request (Phase 6, Chunks 6.2 + 6.3).
 *
 * Shows the request, its customer, vehicle, stored location + frozen ETA
 * (never recomputed — Decision 32), the assigned Tireman and the last admin
 * who handled it, and a read-only straight-line map to the shop (Decisions
 * 9/33 — no routing, no live tracking).
 *
 * Actions (Phase 6.3, owner-approved rules): POST + CSRF, then redirect back
 * here (PRG) with a one-time session flash.
 *   pending  -> accept (an ACTIVE Tireman must be chosen in the same action) | reject
 *   accepted -> reassign Tireman (active, status unchanged) | complete (only with a
 *               Tireman assigned) | reject
 *   rejected / completed are final — no actions.
 * The rules live in OtgStatus::canTransition() and are enforced again by the
 * repository's guarded UPDATEs, so a request changed in another tab is never
 * overwritten. The acting admin always comes from the session.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Repository\TiremanRepository;
use VulcaTrack\Support\OtgStatus;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin    = require_admin();
$shop     = require VULCATRACK_ROOT . '/config/shop.php';
$pdo      = vulcatrack_db();
$requests = new ServiceRequestRepository($pdo);
$tiremen  = new TiremanRepository($pdo);

/** A positive whole number that fits a signed INT id column; anything else is 0 ("none"). */
function rescue_id($raw): int
{
    return (is_string($raw) && preg_match('/^[1-9]\d{0,9}$/', $raw) && (int) $raw <= 2147483647)
        ? (int) $raw
        : 0;
}

$requestId = rescue_id($_GET['id'] ?? '');
$request   = $requestId > 0 ? $requests->findForAdmin($requestId) : null;

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

// --- actions (POST only, CSRF, then redirect back) -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminId = (int) $admin['id'];   // never from the form
    $action  = (string) ($_POST['_action'] ?? '');
    $stale   = ['error', 'This request changed. Reload the page and try again.'];
    $flash   = ['error', 'That action could not be completed. Please try again.'];

    /** The chosen Tireman if it is a real, ACTIVE one; else null. */
    $activeTireman = function () use ($tiremen): ?array {
        $id = rescue_id($_POST['tireman_id'] ?? '');
        $t  = $id > 0 ? $tiremen->findById($id) : null;
        return ($t !== null && $t['is_active'] === 1) ? $t : null;
    };

    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $flash = ['error', 'Your session expired. Please try again.'];
    } else {
        try {
            switch ($action) {
                case 'accept':
                    $t = $activeTireman();
                    if ($t === null) {
                        $flash = ['error', 'Choose an active Tireman to accept this request.'];
                    } elseif ($requests->accept($requestId, $t['tireman_id'], $adminId)) {
                        $flash = ['notice', "Request accepted. {$t['name']} is assigned."];
                    } else {
                        $flash = $stale;
                    }
                    break;

                case 'reassign':
                    // The assignment the admin was looking at ('' = none). A malformed value is stale.
                    $rawExpected = (string) ($_POST['expected_tireman_id'] ?? '');
                    $expected    = $rawExpected === '' ? null : rescue_id($rawExpected);
                    $t = $activeTireman();
                    if ($t === null) {
                        $flash = ['error', 'Choose an active Tireman.'];
                    } elseif ($expected === 0) {
                        $flash = $stale;
                    } elseif ($t['tireman_id'] === $expected) {
                        $flash = ['error', "{$t['name']} is already assigned to this request."];
                    } elseif ($requests->reassign($requestId, $expected, $t['tireman_id'], $adminId)) {
                        $flash = ['notice', "Tireman changed. {$t['name']} is now assigned."];
                    } else {
                        $flash = $stale;
                    }
                    break;

                case 'reject':
                    // The status the admin was looking at; the UPDATE only applies if it is still that.
                    $from = (string) ($_POST['expected_status'] ?? '');
                    if (!OtgStatus::canTransition($from, 'rejected')) {
                        break; // generic error
                    }
                    $flash = $requests->reject($requestId, $from, $adminId)
                        ? ['notice', 'Request rejected.']
                        : $stale;
                    break;

                case 'complete':
                    if ($request['status'] === 'accepted' && $request['tireman_id'] === null) {
                        $flash = ['error', 'Assign a Tireman before completing this request.'];
                        break;
                    }
                    // The UPDATE itself also requires status 'accepted' and an assigned Tireman.
                    $flash = $requests->complete($requestId, $adminId)
                        ? ['notice', 'Request marked as completed.']
                        : $stale;
                    break;
            }
        } catch (\PDOException $e) {
            error_log('VulcaTrack rescue action failed: ' . $e->getMessage());
            $flash = ['error', 'That action could not be completed. Please try again.'];
        }
    }

    $_SESSION['rescue_flash'][] = $flash;
    header('Location: ' . vulcatrack_url('/admin/rescue-view.php?id=' . $requestId));
    exit;
}

$flashes = is_array($_SESSION['rescue_flash'] ?? null) ? $_SESSION['rescue_flash'] : [];
unset($_SESSION['rescue_flash']);

$status        = (string) $request['status'];
$activeTiremen = OtgStatus::isFinal($status) ? [] : $tiremen->listActive();
$currentTid    = $request['tireman_id'] !== null ? (int) $request['tireman_id'] : null;

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
<?php foreach ($flashes as [$type, $message]): ?>
  <p class="<?= $type === 'error' ? 'error' : 'notice' ?>"><?= e($message) ?></p>
<?php endforeach; ?>

<?php if (OtgStatus::isFinal($status)): ?>
  <p class="muted">This request is <?= e(strtolower(OtgStatus::adminLabel($status))) ?>. It is final and cannot be changed.</p>
<?php else: ?>
  <section class="card rescue-actions">
    <h2>Actions</h2>
    <?php $formUrl = vulcatrack_url('/admin/rescue-view.php?id=' . (int) $request['request_id']); ?>

    <?php if ($status === 'pending'): ?>
      <?php if ($activeTiremen): ?>
        <form method="post" action="<?= e($formUrl) ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="_action" value="accept">
          <label for="accept-tireman">Accept and assign a Tireman</label>
          <select id="accept-tireman" name="tireman_id" required>
            <option value="">Choose a Tireman…</option>
            <?php foreach ($activeTiremen as $t): ?>
              <option value="<?= (int) $t['tireman_id'] ?>"><?= e($t['name']) ?> — <?= e($t['contact_number']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit">Accept request</button>
        </form>
      <?php else: ?>
        <p class="muted">No active Tiremen. <a href="<?= e(vulcatrack_url('/admin/tiremen.php')) ?>">Add or activate one</a> before accepting this request.</p>
      <?php endif; ?>

    <?php else: /* accepted */ ?>
      <?php $others = array_values(array_filter($activeTiremen, fn ($t) => $t['tireman_id'] !== $currentTid)); ?>
      <?php if ($others): ?>
        <form method="post" action="<?= e($formUrl) ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="_action" value="reassign">
          <input type="hidden" name="expected_tireman_id" value="<?= $currentTid !== null ? $currentTid : '' ?>">
          <label for="reassign-tireman"><?= $currentTid !== null ? 'Reassign to another Tireman' : 'Assign a Tireman' ?></label>
          <select id="reassign-tireman" name="tireman_id" required>
            <option value="">Choose a Tireman…</option>
            <?php foreach ($others as $t): ?>
              <option value="<?= (int) $t['tireman_id'] ?>"><?= e($t['name']) ?> — <?= e($t['contact_number']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit"><?= $currentTid !== null ? 'Change Tireman' : 'Assign Tireman' ?></button>
        </form>
      <?php else: ?>
        <p class="muted"><?= $currentTid !== null ? 'No other active Tireman to reassign to.' : 'No active Tireman to assign.' ?></p>
      <?php endif; ?>

      <?php if ($currentTid !== null): ?>
        <form method="post" action="<?= e($formUrl) ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="_action" value="complete">
          <button type="submit" onclick="return confirm('Mark this request as completed? This cannot be undone.');">Mark as completed</button>
        </form>
      <?php else: ?>
        <p class="muted">A Tireman must be assigned before this request can be completed.</p>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="<?= e($formUrl) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="_action" value="reject">
      <input type="hidden" name="expected_status" value="<?= e($status) ?>">
      <button type="submit" class="linklike"
              onclick="return confirm('Reject this request? This cannot be undone.');">Reject request</button>
    </form>
  </section>
<?php endif; ?>

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
