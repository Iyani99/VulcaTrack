<?php
/**
 * Post-submission confirmation for a new rescue request (Phase 7.4b-c: the
 * Request Confirmation Figma). customer/booking.php renders it for ?new=1 —
 * the PRG target of customer/rescue.php — while the request is still pending,
 * from the stored, owner-scoped request (never from the submitted form).
 *
 * Figma differences, on purpose: no fee / total table (a rescue is not priced
 * at booking — the shop records the sale after the job); "Request submitted /
 * Pending review" instead of "confirmed / provider dispatched" (nothing is
 * accepted or assigned yet); "View request status" instead of live tracking.
 *
 * Expects: array $request (findForCustomer row), array $shop, ?string $contactNumber,
 *          bool $hasLocation, array $vehicleBits
 */

use VulcaTrack\Support\OtgStatus;

$requestUrl = vulcatrack_url('/customer/booking.php?id=' . (int) $request['request_id']);
?>
<div class="rc">
<section class="rc-card" aria-labelledby="rc-title">
  <div class="rc-hero">
    <span class="rc-hero__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M8.3 12.2l2.6 2.6 4.8-5.3"/></svg></span>
    <h1 id="rc-title">Request submitted</h1>
    <p>Your rescue request has been received. The shop will review your request.<?= $contactNumber !== null && $contactNumber !== ''
      ? ' Contact number on file: <strong>' . e($contactNumber) . '</strong>.' : '' ?></p>
  </div>

  <div class="rc-body">
    <div class="rc-top">
      <div class="rc-box">
        <p class="cu-label">Request number</p>
        <p class="rc-box__value">#<?= (int) $request['request_id'] ?></p>
      </div>
      <div class="rc-box rc-box--status">
        <p class="cu-label">Current status</p>
        <p class="rc-box__value"><?= e(OtgStatus::label($request['status'], $request['tireman_id'] !== null)) ?></p>
      </div>
    </div>

    <div class="rc-grid">
      <div class="rc-facts">
        <div class="rc-fact">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 16V11l2-5h10l2 5v5M5 16h14M5 16v2.5M19 16v2.5M4 11h16"/></svg>
          <div>
            <p class="cu-label">Vehicle</p>
            <p class="rc-fact__value"><?= e($request['plate_number']) ?><?= $vehicleBits ? ' &middot; ' . e(implode(' ', $vehicleBits)) : '' ?></p>
          </div>
        </div>
        <div class="rc-fact rc-fact--issue">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2.5 20h19z"/><path d="M12 10v4.5M12 17v.5"/></svg>
          <div>
            <p class="cu-label">Reported issue</p>
            <p class="rc-fact__value rc-fact__text"><?= nl2br(e($request['problem_description'])) ?></p>
          </div>
        </div>
        <div class="rc-fact">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
          <div>
            <p class="cu-label">Estimated response time</p>
            <p class="rc-fact__value"><?= $request['eta_minutes'] !== null ? '~ ' . (int) $request['eta_minutes'] . ' mins' : 'Not available' ?></p>
            <p class="rc-fact__hint">Calculated once when you submitted; it does not update.</p>
          </div>
        </div>
      </div>

      <div class="rc-map">
        <?php if ($hasLocation): ?>
          <p class="rc-map__head"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-7.2-7-12a7 7 0 0 1 14 0c0 4.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/></svg>Saved location &middot; <?= e(number_format((float) $request['latitude'], 5)) ?>, <?= e(number_format((float) $request['longitude'], 5)) ?></p>
          <div id="otg-map" class="otg-map" data-readonly="1"
               data-shop-lat="<?= e((string) $shop['latitude']) ?>"
               data-shop-lng="<?= e((string) $shop['longitude']) ?>"
               data-shop-name="<?= e($shop['name'] ?? 'Shop') ?>"
               data-cust-lat="<?= e((string) $request['latitude']) ?>"
               data-cust-lng="<?= e((string) $request['longitude']) ?>"></div>
          <p class="rc-map__note">The line is a straight-line reference to the shop, not a driving route.</p>
        <?php else: ?>
          <p class="rc-map__head">No location was captured for this request.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="rc-actions">
    <a class="cu-btn cu-btn--outline" href="<?= e(vulcatrack_url('/customer/dashboard.php')) ?>">Return to dashboard</a>
    <a class="cu-btn cu-btn--red" href="<?= e($requestUrl) ?>">View request status</a>
  </div>
</section>
</div>
<?php if ($hasLocation): ?>
<script src="<?= e(vulcatrack_asset('/assets/js/otg-map.js')) ?>" defer></script>
<?php endif; ?>
