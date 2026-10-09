<?php
/**
 * Customer feedback on their own COMPLETED Rescue request (Phase 7.4c,
 * Decision 78): a required 1–5 star rating and an optional comment (≤ 500
 * characters), once per request.
 *
 * This is feedback on the request, not a Tireman rating system: the Tireman
 * who serviced it is shown by name only (no phone after completion), and
 * nothing is scored, averaged or ranked. The form sends only `rating` and
 * `comment`; the request id comes from the URL and is resolved through the
 * customer-scoped lookup. Saving is one guarded UPDATE
 * (ServiceRequestRepository::submitFeedback) that never changes the status,
 * Tireman, admin_id or updated_at. "Skip for now" is just a link back.
 *
 * GET: not the customer's -> 404; not completed / no Tireman -> "not
 * available"; already rated -> redirect to Request Status (which shows it).
 * POST success -> Request Status ?rated=1 (PRG).
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Support\Validator;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$customerId = (int) $customer['id'];
$repo = new ServiceRequestRepository(vulcatrack_db());

$requestId = isset($_GET['id']) && is_string($_GET['id']) ? (int) $_GET['id'] : 0;
$request = $requestId > 0 ? $repo->findForCustomer($requestId, $customerId) : null;
$statusUrl = vulcatrack_url('/customer/booking.php?id=' . $requestId);

if ($request !== null && $request['feedback_submitted_at'] !== null) {
    // one submission only: the saved feedback is shown on Request Status; a
    // POST here is a stale tab / double submit, so say it was not saved again
    $again = $_SERVER['REQUEST_METHOD'] === 'POST' ? '&feedback=exists' : '';
    header('Location: ' . $statusUrl . $again, true, 303);
    exit;
}

$eligible = $request !== null && $request['status'] === 'completed' && $request['tireman_id'] !== null;

$errors = [];
$oldRating = '';
$oldComment = '';

if ($eligible && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldRating = is_string($_POST['rating'] ?? null) ? $_POST['rating'] : '';
    $oldComment = is_string($_POST['comment'] ?? null) ? $_POST['comment'] : '';

    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $v = new Validator();
        $rating = $v->rating('rating', $_POST['rating'] ?? null);
        $comment = $v->optionalMultilineText('comment', $_POST['comment'] ?? null, 'Comment', 500);

        if ($v->passes()) {
            if ($repo->submitFeedback($requestId, $customerId, $rating, $comment)) {
                header('Location: ' . vulcatrack_url('/customer/booking.php?id=' . $requestId . '&rated=1'));
                exit;
            }
            // Nothing changed: find out why (another tab may have saved first).
            $now = $repo->findForCustomer($requestId, $customerId);
            if ($now !== null && $now['feedback_submitted_at'] !== null) {
                header('Location: ' . vulcatrack_url('/customer/booking.php?id=' . $requestId . '&feedback=exists'));
                exit;
            }
            $errors['form'] = 'Feedback is not available for this request.';
        } else {
            $errors = $v->errors();
        }
    }
}

/** "Sep 28, 2026 · 1:57 PM" from a stored DATETIME (display only). */
function feedback_when(string $datetime): string
{
    $t = strtotime($datetime);
    return $t === false ? $datetime : date('M j, Y · g:i A', $t);
}

$pageTitle = $request ? 'Rate Request #' . $requestId : 'Request not found';
$navActive = 'bookings';
$mainClass = 'app--wide';
if ($request === null) {
    http_response_code(404);
}
require __DIR__ . '/../src/Views/partials/customer_top.php';

if ($request === null) {
    $notFoundTitle = 'Request not found';
    $notFoundMessage = 'That request is not on your account.';
    $notFoundBackUrl = vulcatrack_url('/customer/bookings.php');
    $notFoundBackLabel = 'Back to my bookings';
    require __DIR__ . '/../src/Views/partials/not_found.php';
    require __DIR__ . '/../src/Views/partials/customer_bottom.php';
    exit;
}

$vehicleName = implode(' ', array_filter([$request['make'] ?? '', $request['model'] ?? '']));
$labels = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent'];
?>
<div class="fb">
<div class="fb-head">
  <div>
    <p class="rs-back"><a href="<?= e($statusUrl) ?>">&larr; Request #<?= (int) $request['request_id'] ?></a></p>
    <h1>Rate Your Service</h1>
    <p class="fb-sub">Tell the shop how this rescue went. Your feedback is saved with this request.</p>
  </div>
  <?php if ($request['status'] === 'completed'): ?>
    <p class="fb-done"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>Service completed</p>
  <?php endif; ?>
</div>

<?php if (!$eligible): ?>
  <section class="cu-card fb-unavailable">
    <h2>Feedback isn&rsquo;t available</h2>
    <p><?= $request['status'] === 'completed'
        ? 'Feedback is not available for this request.'
        : 'Feedback is available once the service is completed.' ?></p>
    <p><a class="cu-btn cu-btn--outline" href="<?= e($statusUrl) ?>">Back to Request Status</a></p>
  </section>
<?php else: ?>
<div class="fb-grid">
  <aside class="fb-side" aria-label="Rescue summary">
    <section class="cu-card fb-summary" aria-labelledby="fb-summary-title">
      <h2 class="cu-label fb-summary__title" id="fb-summary-title">Rescue summary</h2>
      <dl class="rs-details">
        <div class="rs-details__ref"><dt>Request</dt><dd>#<?= (int) $request['request_id'] ?> <span class="cu-status cu-status--completed">Completed</span></dd></div>
        <div><dt>Submitted</dt><dd><?= e(feedback_when((string) $request['requested_at'])) ?></dd></div>
        <div><dt>Completed</dt><dd><?= e(feedback_when((string) $request['updated_at'])) ?></dd></div>
        <div><dt>Vehicle</dt><dd><?= $vehicleName !== '' ? e($vehicleName) : e($request['vehicle_type'] ?: 'Vehicle') ?><span class="fb-plate"><?= e($request['plate_number']) ?></span><?= $vehicleName !== '' && !empty($request['vehicle_type']) ? '<span class="fb-muted">' . e($request['vehicle_type']) . '</span>' : '' ?></dd></div>
        <div><dt>Problem</dt><dd><?= nl2br(e($request['problem_description'])) ?></dd></div>
        <div><dt>ETA at request time</dt><dd><?= $request['eta_minutes'] !== null ? '~ ' . (int) $request['eta_minutes'] . ' mins' : 'Not available' ?></dd></div>
      </dl>
    </section>
    <section class="cu-card fb-tireman" aria-labelledby="fb-tireman-title">
      <h2 class="cu-label fb-summary__title" id="fb-tireman-title">Serviced by</h2>
      <div class="rs-person">
        <span class="rs-person__avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9.5"/><circle cx="12" cy="10" r="3.2"/><path d="M6.3 18.4c1.3-2.2 3.3-3.3 5.7-3.3s4.4 1.1 5.7 3.3"/></svg></span>
        <p class="rs-person__name"><?= e($request['tireman_name']) ?></p>
      </div>
    </section>
  </aside>

  <div class="fb-main">
    <?php if (!empty($errors['form'])): ?>
      <p class="cu-alert" role="alert"><?= e($errors['form']) ?></p>
    <?php elseif ($errors): ?>
      <p class="cu-alert" role="alert">Please check the highlighted fields.</p>
    <?php endif; ?>

    <form method="post" action="<?= e(vulcatrack_url('/customer/feedback.php?id=' . (int) $request['request_id'])) ?>" novalidate>
      <?= Csrf::field() ?>

      <fieldset class="cu-card fb-rate<?= !empty($errors['rating']) ? ' fb-rate--error' : '' ?>"<?= !empty($errors['rating']) ? ' aria-describedby="err-rating"' : '' ?>>
        <legend class="fb-section"><span>Your rating</span><span class="fb-req">Required</span></legend>
        <p class="fb-rate__hint">Choose 1 to 5 stars.</p>
        <div class="fb-stars">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <input type="radio" id="rating-<?= $i ?>" name="rating" value="<?= $i ?>"<?= $oldRating === (string) $i ? ' checked' : '' ?><?= $i === 1 ? ' required' : '' ?>>
            <label for="rating-<?= $i ?>" title="<?= $i ?> &mdash; <?= e($labels[$i]) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.8l2.8 5.9 6.4.8-4.7 4.4 1.2 6.4L12 17.2l-5.7 3.1 1.2-6.4-4.7-4.4 6.4-.8z"/></svg><span class="sr-only"><?= $i ?> star<?= $i > 1 ? 's' : '' ?></span></label>
          <?php endfor; ?>
        </div>
        <p class="fb-rate__value" aria-hidden="true"><span class="fb-rate__none">No rating selected</span><?php foreach ($labels as $n => $word): ?><span class="fb-rate__is fb-rate__is--<?= $n ?>"><?= $n ?> out of 5 &mdash; <?= e($word) ?></span><?php endforeach; ?></p>
        <?php if (!empty($errors['rating'])): ?><small class="error" id="err-rating"><?= e($errors['rating']) ?></small><?php endif; ?>
      </fieldset>

      <section class="cu-card fb-comment">
        <div class="fb-comment__head">
          <label class="fb-section" for="comment">Comments <span class="fb-opt">(optional)</span></label>
          <span class="fb-count" id="comment-count">Up to 500 characters</span>
        </div>
        <textarea id="comment" name="comment" rows="5" maxlength="500"
                  placeholder="How was the service? e.g. how quickly the Tireman arrived and how the repair went."
                  aria-describedby="comment-count<?= !empty($errors['comment']) ? ' err-comment' : '' ?>"<?= !empty($errors['comment']) ? ' aria-invalid="true"' : '' ?>><?= e($oldComment) ?></textarea>
        <?php if (!empty($errors['comment'])): ?><small class="error" id="err-comment"><?= e($errors['comment']) ?></small><?php endif; ?>
      </section>

      <p class="fb-note">Feedback can be submitted once and can&rsquo;t be edited afterwards. It is shared with the shop only.</p>

      <div class="fb-actions">
        <a class="cu-btn cu-btn--outline" href="<?= e($statusUrl) ?>">Skip for now</a>
        <button type="submit" class="cu-btn cu-btn--red">Submit Feedback<svg class="cu-btn__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
      </div>
    </form>
  </div>
</div>
<script>
  // optional: live character count (the form works without it)
  (function () {
    var t = document.getElementById('comment'), c = document.getElementById('comment-count');
    if (!t || !c) return;
    var show = function () { c.textContent = t.value.replace(/\r\n/g, '\n').length + ' / 500 characters'; };
    t.addEventListener('input', show);
    show();
  })();
</script>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
