<?php
/**
 * Customer dashboard / home (Phase 7.4b-a: follows the Customer Home Figma).
 *
 * Every figure is the signed-in customer's own data, read through the existing
 * owner-scoped repository methods — nothing here is sample content:
 *   - Saved vehicles: active (not removed) vehicles.
 *   - Active requests: pending + accepted (the non-final statuses).
 *   - Total rescues: completed requests, out of all requests made.
 *   - Active rescue panel: the newest pending / accepted request, with its
 *     frozen ETA snapshot and — once assigned — the Tireman's name. There is no
 *     live tracking: the panel links to the existing request status page.
 *   - Recent activity: the newest requests from the My Bookings list. Rescue
 *     requests carry no price (a Rescue's sale is recorded later at the shop
 *     POS), so there is no Amount column.
 */

use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Repository\VehicleRepository;
use VulcaTrack\Support\OtgStatus;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$customer = require_customer();
$customerId = (int) $customer['id'];
$pdo = vulcatrack_db();

$vehicles = new VehicleRepository($pdo);
$requests = new ServiceRequestRepository($pdo);

$activeVehicles = $vehicles->countActiveForCustomer($customerId);
$openRequests   = $requests->countOpenForCustomer($customerId);
$history        = $requests->listForCustomer($customerId); // newest first, same list as My Bookings

$completed = count(array_filter($history, fn ($r) => $r['status'] === 'completed'));
$open      = array_values(array_filter($history, fn ($r) => !OtgStatus::isFinal($r['status'])));
// the newest open request, re-read owner-scoped for the Tireman's name
$active    = $open ? $requests->findForCustomer((int) $open[0]['request_id'], $customerId) : null;
$recent    = array_slice($history, 0, 5);

/** "Sep 28, 2026" + "1:57 PM" from a stored DATETIME (display only). */
function dash_when(string $datetime): array
{
    $t = strtotime($datetime);
    return $t === false ? [$datetime, ''] : [date('M j, Y', $t), date('g:i A', $t)];
}

/**
 * Status wording for the dashboard. Accepting a request assigns an active
 * Tireman in the same admin action (Decision 66), so "accepted without a
 * Tireman" is only a defensive fallback for malformed / legacy data — shown
 * neutrally, never as an assignment still in progress.
 */
function dash_label(string $status, bool $tiremanAssigned): string
{
    return $status === 'accepted' && !$tiremanAssigned ? 'Accepted request' : OtgStatus::label($status, $tiremanAssigned);
}

$pageTitle = 'Home';
$navActive = 'dashboard';
$mainClass = 'app--wide';
require __DIR__ . '/../src/Views/partials/customer_top.php';
?>
<div class="cd-hero">
  <div>
    <h1>Hello, <?= e($customer['name']) ?>!</h1>
    <p class="cd-hero__sub">Ready to get back on the road?</p>
  </div>
  <a class="cu-btn cu-btn--red cu-btn--lg" href="<?= e(vulcatrack_url('/customer/rescue.php')) ?>"><svg class="cu-btn__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M4.2 7.5l15.6 9M4.2 16.5l15.6-9"/></svg>Book a Rescue Now</a>
</div>

<div class="cd-stats">
  <section class="cu-card cd-stat">
    <h2 class="cu-label"><svg class="cu-label__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 16V11l2-5h10l2 5v5M5 16h14M5 16v2.5M19 16v2.5M4 11h16"/><circle cx="8" cy="13.5" r=".6"/><circle cx="16" cy="13.5" r=".6"/></svg>Saved vehicles</h2>
    <p class="cd-stat__value"><?= (int) $activeVehicles ?> Active</p>
    <?php if ($activeVehicles === 0): ?>
      <p class="cd-stat__note">Add a vehicle before booking a rescue.</p>
    <?php endif; ?>
  </section>
  <section class="cu-card cd-stat<?= $openRequests > 0 ? ' cu-card--accent' : '' ?>">
    <h2 class="cu-label"><svg class="cu-label__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17"/><path d="M9 4V2.8h6V4M8.5 10h7M8.5 14h7M8.5 18h4"/></svg>Active requests</h2>
    <p class="cd-stat__value"><?= (int) $openRequests ?> Open</p>
    <p class="cd-stat__note">Pending review or accepted.</p>
  </section>
  <section class="cu-card cd-stat">
    <h2 class="cu-label"><svg class="cu-label__icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12.3l2.7 2.7L16.2 9.5"/></svg>Total rescues</h2>
    <p class="cd-stat__value"><?= (int) $completed ?> Completed</p>
    <p class="cd-stat__note"><?= count($history) ?> request<?= count($history) === 1 ? '' : 's' ?> made in total.</p>
  </section>
</div>

<?php if ($active !== null): ?>
  <?php
  $tiremanAssigned = $active['tireman_id'] !== null;
  $isAccepted = $active['status'] === 'accepted';
  ?>
  <section class="cu-card cd-active" aria-labelledby="cd-active-title">
    <div class="cd-active__main">
      <p class="cu-chip"><?= $isAccepted ? 'Active rescue' : 'Request received' ?></p>
      <h2 class="cd-active__title" id="cd-active-title"><?= e(dash_label($active['status'], $tiremanAssigned)) ?></h2>
      <p class="cd-active__meta">
        <span>Request #<?= (int) $active['request_id'] ?> &middot; <?= e($active['plate_number']) ?></span>
        <?php if ($isAccepted && $tiremanAssigned): ?>
          <span>Tireman: <strong><?= e($active['tireman_name']) ?></strong></span>
        <?php elseif ($isAccepted): ?>
          <span>Tireman assignment information is unavailable.</span>
        <?php else: ?>
          <span>The shop will review your request.</span>
        <?php endif; ?>
        <span><svg class="cd-active__icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>ETA at request time: <?= $active['eta_minutes'] !== null ? (int) $active['eta_minutes'] . ' mins' : 'not available' ?></span>
      </p>
      <?php if (count($open) > 1): ?>
        <p class="cd-active__more">You have <?= count($open) ?> open requests. <a href="<?= e(vulcatrack_url('/customer/bookings.php')) ?>">See all in My Bookings</a></p>
      <?php endif; ?>
    </div>
    <a class="cu-btn cu-btn--outline" href="<?= e(vulcatrack_url('/customer/booking.php?id=' . (int) $active['request_id'])) ?>">View status</a>
  </section>
<?php else: ?>
  <section class="cu-card cd-active cd-active--none" aria-labelledby="cd-active-title">
    <div class="cd-active__main">
      <p class="cu-chip cu-chip--quiet">No active rescue</p>
      <h2 class="cd-active__title" id="cd-active-title">You have no pending or accepted requests.</h2>
      <p class="cd-active__meta"><span>Need roadside help? Book a rescue and the shop will review it.</span></p>
    </div>
    <?php if ($history): ?>
      <a class="cu-btn cu-btn--outline" href="<?= e(vulcatrack_url('/customer/bookings.php')) ?>">View history</a>
    <?php endif; ?>
  </section>
<?php endif; ?>

<div class="cd-columns">
  <section class="cd-quick" aria-labelledby="cd-quick-title">
    <h2 class="cu-section-title" id="cd-quick-title">Quick Actions</h2>
    <ul class="cd-quick__grid">
      <li><a class="cd-quick__item" href="<?= e(vulcatrack_url('/customer/rescue.php')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-7.2-7-12a7 7 0 0 1 14 0c0 4.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/></svg>Book a Rescue</a></li>
      <li><a class="cd-quick__item" href="<?= e(vulcatrack_url('/customer/bookings.php')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17"/><path d="M8.5 9h7M8.5 13h7M8.5 17h4"/></svg>My Bookings</a></li>
      <li><a class="cd-quick__item" href="<?= e(vulcatrack_url('/customer/vehicles.php')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 16V11l2-5h10l2 5v5M5 16h14M5 16v2.5M19 16v2.5M4 11h16"/></svg>My Vehicles</a></li>
      <li><a class="cd-quick__item" href="<?= e(vulcatrack_url('/customer/profile.php')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg>Profile</a></li>
    </ul>
  </section>

  <section class="cd-recent" aria-labelledby="cd-recent-title">
    <h2 class="cu-section-title" id="cd-recent-title">Recent Activity</h2>
    <?php if (!$recent): ?>
      <p class="cu-empty">No rescue requests yet. Your requests will appear here.</p>
    <?php else: ?>
      <div class="table-scroll">
      <table class="cu-table">
        <thead><tr><th>Date</th><th>Request</th><th>Vehicle</th><th>Status</th><th><span class="sr-only">Details</span></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <?php [$day, $time] = dash_when((string) $r['requested_at']); ?>
          <tr>
            <td class="nowrap"><?= e($day) ?><?php if ($time !== ''): ?><span class="cu-table__sub"><?= e($time) ?></span><?php endif; ?></td>
            <td class="nowrap">#<?= (int) $r['request_id'] ?></td>
            <td class="nowrap"><?= e($r['plate_number']) ?></td>
            <td><span class="cu-status cu-status--<?= e($r['status']) ?>"><?= e(dash_label($r['status'], $r['tireman_id'] !== null)) ?></span></td>
            <td class="cu-table__action"><a href="<?= e(vulcatrack_url('/customer/booking.php?id=' . (int) $r['request_id'])) ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if (count($history) > count($recent)): ?>
        <p class="cd-recent__more"><a href="<?= e(vulcatrack_url('/customer/bookings.php')) ?>">View all <?= count($history) ?> requests in My Bookings</a></p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../src/Views/partials/customer_bottom.php'; ?>
