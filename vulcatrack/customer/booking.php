<?php
/**
 * Customer-facing rescue-request status / detail.
 *
 * Shows the frozen ETA snapshot (never recomputed), the route between the two
 * fixed endpoints (redrawn client-side, not persisted), and -- while the
 * request is accepted with an assigned Tireman -- "Tireman is on the way" with
 * the Tireman's name and contact number. A completed request keeps the
 * Tireman's name as history but never says "on the way"; a rejected one shows
 * no Tireman. Read-only for the customer — no status or Tireman actions, no
 * live tracking (Phase 7.4b-d: Request Status Figma, for all four statuses).
 * ?new=1 (right after submitting) shows the confirmation view while the
 * request is still pending, else a short "submitted" notice on this page.
 * Phase 7.4c (Decision 78): a completed request offers "Rate this service"
 * (customer/feedback.php) until feedback is given, then shows it read-only.
 */

use VulcaTrack\Repository\CustomerRepository;
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

// ?new=1 is the PRG target right after a submission (customer/rescue.php):
// while the request is still pending it gets the confirmation view (Phase
// 7.4b-c); otherwise — or later — this is the normal status page.
$confirming = $request !== null && isset($_GET['new']) && $request['status'] === 'pending';

$pageTitle = $request ? ($confirming ? 'Request submitted' : 'Request #' . $requestId) : 'Request not found';
$navActive = $confirming ? 'rescue' : 'bookings';
$mainClass = 'app--wide';
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

if ($confirming) {
    $contactRow = (new CustomerRepository(vulcatrack_db()))->findById((int) $customer['id']);
    $contactNumber = $contactRow !== null ? (string) $contactRow['contact_number'] : null;
    require __DIR__ . '/../src/Views/customer_rescue_confirmation.php';
    require __DIR__ . '/../src/Views/partials/customer_bottom.php';
    exit;
}

/** "Sep 28, 2026 · 1:57 PM" from a stored DATETIME (display only). */
function booking_when(string $datetime): string
{
    $t = strtotime($datetime);
    return $t === false ? $datetime : date('M j, Y · g:i A', $t);
}

// Read-only status view (Phase 7.4b-d: Request Status Figma). Per-status copy
// for the four real statuses; the customer has no actions on the request.
$status = (string) $request['status'];
$label  = OtgStatus::label($status, $tiremanAssigned);
switch ($status) {
    case 'accepted':
        $lead = $tiremanAssigned
            ? 'Your request was accepted and a Tireman has been assigned.'
            : 'Tireman assignment information is unavailable.';
        break;
    case 'rejected':
        $lead = 'The shop was unable to take this request. You can submit a new request.';
        break;
    case 'completed':
        $lead = 'This service has been completed. Thank you for using VulcaTrack.';
        break;
    default: // pending
        $lead = 'Request submitted. The shop will review your request.';
}
$final = OtgStatus::isFinal($status);
// Phase 7.4c (Decision 78): a completed, serviced request can get one piece of
// customer feedback; once given it is shown read-only below (no edit / resubmit).
$hasFeedback = $request['feedback_submitted_at'] !== null;
$canRate = $status === 'completed' && $tiremanAssigned && !$hasFeedback;
?>
<div class="rs">
<?php if (isset($_GET['new'])): ?>
  <p class="cu-notice" role="status">Your rescue request was submitted.</p>
<?php endif; ?>
<?php if ($hasFeedback && isset($_GET['rated'])): ?>
  <p class="cu-notice" role="status">Thank you, your feedback was submitted.</p>
<?php elseif ($hasFeedback && isset($_GET['feedback'])): ?>
  <p class="cu-notice" role="status">Feedback for this request was already submitted.</p>
<?php endif; ?>

<div class="rs-titlebar">
  <div>
    <p class="rs-back"><a href="<?= e(vulcatrack_url('/customer/bookings.php')) ?>">&larr; My Bookings</a></p>
    <h1>Request #<?= (int) $request['request_id'] ?></h1>
    <p class="rs-meta">Submitted <?= e(booking_when((string) $request['requested_at'])) ?></p>
  </div>
</div>

<section class="rs-banner rs-banner--<?= e($status) ?>" aria-labelledby="rs-status">
  <div class="rs-banner__main">
    <h2 id="rs-status"><span class="rs-dot" aria-hidden="true"></span><?= e($label) ?></h2>
    <p><?= e($lead) ?></p>
  </div>
  <div class="rs-banner__eta">
    <p class="cu-label">ETA at request time</p>
    <p class="rs-eta"><?= $request['eta_minutes'] !== null ? '~ ' . (int) $request['eta_minutes'] . ' mins' : 'Not available' ?></p>
    <p class="rs-eta__note">Estimated when you submitted; it does not update.</p>
  </div>
  <?php if ($status === 'rejected'): ?>
    <div class="rs-banner__actions">
      <a class="cu-btn cu-btn--red" href="<?= e(vulcatrack_url('/customer/rescue.php')) ?>">Book a new rescue</a>
    </div>
  <?php elseif ($canRate): ?>
    <div class="rs-banner__actions">
      <a class="cu-btn cu-btn--red" href="<?= e(vulcatrack_url('/customer/feedback.php?id=' . (int) $request['request_id'])) ?>"><svg class="cu-btn__icon rs-star" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.8l2.8 5.9 6.4.8-4.7 4.4 1.2 6.4L12 17.2l-5.7 3.1 1.2-6.4-4.7-4.4 6.4-.8z"/></svg>Rate this service</a>
    </div>
  <?php endif; ?>
</section>

<div class="rs-grid">
  <div class="rs-side">
    <?php if ($status !== 'rejected' && !($status === 'completed' && !$tiremanAssigned)): ?>
      <section class="cu-card rs-card" aria-labelledby="rs-tireman-title">
        <h2 class="cu-label rs-card__title" id="rs-tireman-title"><svg class="cu-label__icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg>Tireman</h2>
        <?php if ($status === 'accepted' && $tiremanAssigned): ?>
          <div class="rs-person">
            <span class="rs-person__avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9.5"/><circle cx="12" cy="10" r="3.2"/><path d="M6.3 18.4c1.3-2.2 3.3-3.3 5.7-3.3s4.4 1.1 5.7 3.3"/></svg></span>
            <p class="rs-person__name"><?= e($request['tireman_name']) ?></p>
          </div>
          <a class="cu-btn cu-btn--outline rs-call" href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $request['tireman_contact'])) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z"/></svg>Call <?= e($request['tireman_contact']) ?></a>
          <p class="rs-card__note">Coordinate directly by phone. There is no in-app messaging or live location.</p>
        <?php elseif ($status === 'accepted'): ?>
          <p class="rs-card__text">Tireman assignment information is unavailable.</p>
        <?php elseif ($status === 'completed'): ?>
          <p class="rs-card__text">Serviced by <?= e($request['tireman_name']) ?>.</p>
        <?php else: ?>
          <p class="rs-card__text">Not assigned yet. A Tireman is assigned when the shop accepts your request.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($hasFeedback): ?>
      <?php $fbRating = (int) $request['feedback_rating']; ?>
      <section class="cu-card rs-card rs-feedback" aria-labelledby="rs-feedback-title">
        <h2 class="cu-label rs-card__title" id="rs-feedback-title"><svg class="cu-label__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.8l2.8 5.9 6.4.8-4.7 4.4 1.2 6.4L12 17.2l-5.7 3.1 1.2-6.4-4.7-4.4 6.4-.8z"/></svg>Your feedback</h2>
        <p class="rs-feedback__rating"><span class="rs-feedback__stars" aria-hidden="true"><?= str_repeat('&#9733;', $fbRating) ?><span class="rs-feedback__off"><?= str_repeat('&#9733;', 5 - $fbRating) ?></span></span><?= $fbRating ?> out of 5</p>
        <?php if ($request['feedback_comment'] !== null): ?>
          <blockquote class="rs-feedback__comment"><?= nl2br(e($request['feedback_comment'])) ?></blockquote>
        <?php endif; ?>
        <p class="rs-card__note">Submitted <?= e(booking_when((string) $request['feedback_submitted_at'])) ?></p>
      </section>
    <?php endif; ?>

    <section class="cu-card rs-card" aria-labelledby="rs-details-title">
      <h2 class="cu-label rs-card__title" id="rs-details-title"><svg class="cu-label__icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.5v.5"/></svg>Request details</h2>
      <dl class="rs-details">
        <div><dt>Problem</dt><dd><?= nl2br(e($request['problem_description'])) ?></dd></div>
        <div><dt>Vehicle</dt><dd><?= e($request['plate_number']) ?><?= $vehicleBits ? ' &middot; ' . e(implode(' ', $vehicleBits)) : '' ?></dd></div>
        <div class="rs-details__ref"><dt>Request number</dt><dd>#<?= (int) $request['request_id'] ?> <span class="cu-status cu-status--<?= e($status) ?>"><?= e($label) ?></span></dd></div>
      </dl>
    </section>
  </div>

  <section class="rs-map" aria-label="Request location">
    <?php if ($hasLocation): ?>
      <p class="rs-map__head"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2.5"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>Your location &middot; <?= e(number_format((float) $request['latitude'], 5)) ?>, <?= e(number_format((float) $request['longitude'], 5)) ?></p>
      <div id="otg-map" class="otg-map" data-readonly="1"
           data-shop-lat="<?= e((string) $shop['latitude']) ?>"
           data-shop-lng="<?= e((string) $shop['longitude']) ?>"
           data-shop-name="<?= e($shop['name'] ?? 'Shop') ?>"
           data-cust-lat="<?= e((string) $request['latitude']) ?>"
           data-cust-lng="<?= e((string) $request['longitude']) ?>"></div>
      <p class="rs-map__note">The saved request location and <?= e($shop['name'] ?? 'the shop') ?>. The line is a straight-line reference, not a driving route<?= $final ? '' : ' or a live position' ?>.</p>
      <script src="<?= e(vulcatrack_asset('/assets/js/otg-map.js')) ?>" defer></script>
    <?php else: ?>
      <p class="rs-map__head">No location was captured for this request.</p>
    <?php endif; ?>
  </section>
</div>
</div>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
