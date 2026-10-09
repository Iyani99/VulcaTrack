<?php
/**
 * Admin — one Rescue (OTG) request (Phase 6, Chunks 6.2 + 6.3).
 *
 * Shows the request, its customer, vehicle, stored location + frozen ETA
 * (never recomputed — Decision 32), the assigned Tireman and the last admin
 * who handled it, and a read-only straight-line map to the shop (Decisions
 * 9/33 — no routing, no live tracking). Phase 7.3b laid it out as a "ticket"
 * (customer / problem panels + an Assigned Tireman strip); the ETA there is
 * the request-time snapshot, labelled as such — never a live estimate.
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
 *
 * Sale (Phase 7.3d): an accepted or completed request with no sale offers
 * "Record sale in POS" (a POST to the POS's start_rescue action); once a sale
 * is linked it is shown here ("Sale recorded" — no payment method is stored)
 * and Reject is no longer offered (the repository refuses it too).
 * Recording a sale never changes the request; completing stays separate.
 *
 * Customer feedback (Phase 7.4c, Decision 78): on a completed request, the
 * customer's one-time rating / comment is shown READ-ONLY — no reply, edit,
 * delete or moderation, and no Tireman score.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Repository\TiremanRepository;
use VulcaTrack\Support\Money;
use VulcaTrack\Support\OtgStatus;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin    = require_admin();
$shop     = require VULCATRACK_ROOT . '/config/shop.php';
$pdo      = vulcatrack_db();
$requests = new ServiceRequestRepository($pdo);
$tiremen  = new TiremanRepository($pdo);
$sales    = new SaleRepository($pdo);

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
                    if ($requests->reject($requestId, $from, $adminId)) {
                        $flash = ['notice', 'Request rejected.'];
                    } elseif ($sales->findForServiceRequest($requestId) !== null) {
                        // The guarded UPDATE refuses a request with a linked sale (Phase 7.3d).
                        $flash = ['error', 'This Rescue has a recorded sale and can no longer be rejected.'];
                    } else {
                        $flash = $stale;
                    }
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
$linkedSale    = $sales->findForServiceRequest($requestId);   // at most one (UNIQUE)

/** A tel: link for a stored phone number (display text escaped as typed). */
function rescue_tel(string $number): string
{
    return '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $number)) . '">' . e($number) . '</a>';
}

/** Stored 'Y-m-d H:i:s' → "Sep 29, 2026 · 4:51 PM" (presentation only; same form as the customer pages). */
function rescue_when(string $datetime): string
{
    $t = strtotime($datetime);
    return $t === false ? $datetime : date('M j, Y · g:i A', $t);
}

$hasLocation  = $request['latitude'] !== null && $request['longitude'] !== null;
$vehicleBits  = array_filter([$request['make'] ?? '', $request['model'] ?? '', $request['vehicle_type'] ?? '']);

$pageTitle = 'Request #' . (int) $request['request_id'];
$navActive = 'rescue';
$useMap    = $hasLocation;
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<?php /* Phase 7.3b layout (Figma "ticket" composition, truthful to the real model):
   header -> request meta -> customer | problem & vehicle panels -> dark
   "Assigned Tireman" strip with the FROZEN request-time ETA -> actions ->
   location + map. Forms, field names and transition rules are unchanged. */ ?>
<header class="pagehead">
  <div>
    <p class="eyebrow">Rescue request</p>
    <h1>Request #<?= (int) $request['request_id'] ?></h1>
  </div>
  <span class="badge <?= e(OtgStatus::badgeClass($request['status'])) ?>"><?= e(OtgStatus::adminLabel($request['status'])) ?></span>
</header>

<dl class="rescue-meta">
  <div><dt>Requested</dt><dd><?= e(rescue_when((string) $request['requested_at'])) ?></dd></div>
  <div><dt>Last updated</dt><dd><?= e(rescue_when((string) $request['updated_at'])) ?></dd></div>
  <div><dt>Handled by</dt><dd><?= $request['admin_id'] !== null ? e($request['admin_name']) : '<span class="muted">Not yet handled by an admin.</span>' ?></dd></div>
</dl>

<?php foreach ($flashes as [$type, $message]): ?>
  <p class="<?= $type === 'error' ? 'error' : 'notice' ?>"><?= e($message) ?></p>
<?php endforeach; ?>

<?php if (OtgStatus::isFinal($status)): ?>
  <p class="muted">This request is <?= e(strtolower(OtgStatus::adminLabel($status))) ?>. It is final and cannot be changed.</p>
<?php endif; ?>

<div class="rescue-panels">
  <section class="card rescue-panel">
    <h2 class="rescue-panel__title">Customer</h2>
    <p class="rescue-panel__lead"><?= e($request['customer_name']) ?></p>
    <dl class="kv">
      <dt>Contact number</dt><dd><?= rescue_tel((string) $request['customer_contact']) ?></dd>
      <dt>Email</dt><dd><?= e($request['customer_email']) ?></dd>
    </dl>
  </section>

  <section class="card rescue-panel">
    <h2 class="rescue-panel__title">Problem &amp; vehicle</h2>
    <p class="rescue-panel__lead rescue-problem"><?= nl2br(e($request['problem_description'])) ?></p>
    <dl class="kv">
      <dt>Vehicle</dt><dd><?= $vehicleBits ? e(implode(' ', $vehicleBits)) : '<span class="muted">—</span>' ?></dd>
      <dt>Plate number</dt><dd><?= e($request['plate_number']) ?></dd>
    </dl>
    <?php if ((int) $request['vehicle_active'] !== 1): ?>
      <p class="muted">The customer has since removed this vehicle from their active list.</p>
    <?php endif; ?>
  </section>
</div>

<section class="rescue-assign" aria-label="Assigned Tireman">
  <div class="rescue-assign__who">
    <p class="rescue-assign__label">Assigned Tireman</p>
    <?php if ($request['tireman_id'] !== null): ?>
      <p class="rescue-assign__name"><?= e($request['tireman_name']) ?><?= (int) $request['tireman_active'] !== 1 ? ' <span class="badge badge--inactive">Inactive</span>' : '' ?></p>
      <p class="rescue-assign__sub"><?= rescue_tel((string) $request['tireman_contact']) ?></p>
    <?php else: ?>
      <p class="rescue-assign__name rescue-assign__name--none">No Tireman assigned.</p>
    <?php endif; ?>
  </div>
  <div class="rescue-assign__eta">
    <p class="rescue-assign__label">ETA at request time</p>
    <p class="rescue-assign__value"><?= $request['eta_minutes'] !== null ? (int) $request['eta_minutes'] . ' minutes' : 'not available' ?></p>
    <p class="rescue-assign__sub">Stored when the customer submitted; it does not change.</p>
  </div>
</section>

<?php /* Phase 7.3d: the sale recorded for this request (at most one), or the way to record it */ ?>
<?php if ($linkedSale !== null): ?>
  <section class="card rescue-sale" aria-labelledby="rescue-sale-title">
    <h2 class="rescue-panel__title" id="rescue-sale-title">Sale</h2>
    <p class="rescue-sale__state">Sale recorded</p>
    <dl class="kv">
      <dt>Sale no.</dt><dd>Sale #<?= (int) $linkedSale['sale_id'] ?></dd>
      <dt>Total</dt><dd class="rescue-sale__total">&#8369;<?= e(Money::formatDisplay((int) $linkedSale['total_amount_centavos'])) ?></dd>
      <dt>Date / time</dt><dd><?= e(rescue_when((string) $linkedSale['sale_date'])) ?></dd>
      <dt>Recorded by</dt><dd><?= e($linkedSale['admin_name']) ?></dd>
    </dl>
    <p><a href="<?= e(vulcatrack_url('/admin/transaction-summary.php?id=' . (int) $linkedSale['sale_id'])) ?>">View Transaction Summary</a></p>
  </section>
<?php elseif (OtgStatus::canRecordSale($status)): ?>
  <section class="card rescue-sale" aria-labelledby="rescue-sale-title">
    <h2 class="rescue-panel__title" id="rescue-sale-title">Sale</h2>
    <p class="muted">No sale recorded for this request yet.<?= $status === 'completed' ? ' It can still be recorded after the request was completed.' : '' ?></p>
    <form method="post" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="_action" value="start_rescue">
      <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
      <button type="submit" class="secondary">Record sale in POS</button>
    </form>
  </section>
<?php endif; ?>

<?php /* Phase 7.4c: the customer's feedback on this completed request — read-only, no admin actions (Decision 78) */ ?>
<?php if ($status === 'completed'): ?>
  <section class="card rescue-feedback" aria-labelledby="rescue-feedback-title">
    <h2 class="rescue-panel__title" id="rescue-feedback-title">Customer feedback</h2>
    <?php if ($request['feedback_submitted_at'] !== null): ?>
      <?php $fbRating = (int) $request['feedback_rating']; ?>
      <p class="rescue-feedback__rating"><span class="rescue-feedback__stars" aria-hidden="true"><?= str_repeat('&#9733;', $fbRating) . str_repeat('&#9734;', 5 - $fbRating) ?></span> <?= $fbRating ?> / 5</p>
      <dl class="kv">
        <dt>Comment</dt><dd><?= $request['feedback_comment'] !== null ? nl2br(e($request['feedback_comment'])) : '<span class="muted">No comment.</span>' ?></dd>
        <dt>Submitted</dt><dd><?= e(rescue_when((string) $request['feedback_submitted_at'])) ?></dd>
      </dl>
    <?php else: ?>
      <p class="muted">No feedback yet.</p>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if (!OtgStatus::isFinal($status)): ?>
  <section class="card rescue-actions">
    <h2 class="rescue-panel__title">Actions</h2>
    <?php $formUrl = vulcatrack_url('/admin/rescue-view.php?id=' . (int) $request['request_id']); ?>
    <div class="rescue-actions__grid">
    <div class="rescue-actions__assign">
    <?php if ($status === 'pending'): ?>
      <?php if ($activeTiremen): ?>
        <form class="rescue-pick" method="post" action="<?= e($formUrl) ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="_action" value="accept">
          <label for="accept-tireman">Accept and assign a Tireman</label>
          <select id="accept-tireman" name="tireman_id" required>
            <option value="">Choose a Tireman…</option>
            <?php foreach ($activeTiremen as $t): ?>
              <option value="<?= (int) $t['tireman_id'] ?>"><?= e($t['name']) ?> — <?= e($t['contact_number']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btnlink">Accept request</button>
        </form>
      <?php else: ?>
        <p class="muted">No active Tiremen. <a href="<?= e(vulcatrack_url('/admin/tiremen.php')) ?>">Add or activate one</a> before accepting this request.</p>
      <?php endif; ?>

    <?php else: /* accepted */ ?>
      <?php $others = array_values(array_filter($activeTiremen, fn ($t) => $t['tireman_id'] !== $currentTid)); ?>
      <?php if ($others): ?>
        <form class="rescue-pick" method="post" action="<?= e($formUrl) ?>">
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
          <button type="submit" class="secondary"><?= $currentTid !== null ? 'Change Tireman' : 'Assign Tireman' ?></button>
        </form>
      <?php else: ?>
        <p class="muted"><?= $currentTid !== null ? 'No other active Tireman to reassign to.' : 'No active Tireman to assign.' ?></p>
      <?php endif; ?>
    <?php endif; ?>
    </div>

    <div class="rescue-actions__close">
    <?php if ($status === 'accepted'): ?>
      <?php if ($currentTid !== null): ?>
        <form method="post" action="<?= e($formUrl) ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="_action" value="complete">
          <button type="submit" class="btnlink" onclick="return confirm('Mark this request as completed? This cannot be undone.');">Mark as completed</button>
        </form>
      <?php else: ?>
        <p class="muted">A Tireman must be assigned before this request can be completed.</p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($linkedSale === null): /* a request with a recorded sale cannot be rejected (enforced by the repository) */ ?>
    <form method="post" action="<?= e($formUrl) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="_action" value="reject">
      <input type="hidden" name="expected_status" value="<?= e($status) ?>">
      <button type="submit" class="linklike"
              onclick="return confirm('Reject this request? This cannot be undone.');">Reject request</button>
    </form>
    <?php endif; ?>
    </div>
    </div>
  </section>
<?php endif; ?>

<section class="card">
  <h2 class="rescue-panel__title">Location</h2>
  <?php if ($hasLocation): ?>
    <dl class="kv">
      <dt>Coordinates</dt>
      <dd><?= e(number_format((float) $request['latitude'], 5)) ?>, <?= e(number_format((float) $request['longitude'], 5)) ?></dd>
    </dl>
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
