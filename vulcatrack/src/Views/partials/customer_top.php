<?php
/**
 * Shell for the signed-in customer area (Phase 7.4b-a: Customer Figma header).
 * A white top bar — brand, the customer nav, the signed-in customer's name and
 * the POST + CSRF logout. Every customer-theme rule in app.css is scoped under
 * the .cust wrapper, so admin and guest (login / register) screens are
 * unaffected. On narrow screens the nav wraps onto its own row (CSS only).
 *
 * Expects:  string $pageTitle
 * Optional: string $navActive  (dashboard|rescue|bookings|profile — the
 *                                account pages, My Vehicles included, use profile)
 *           string $mainClass  (extra class on <main>, e.g. 'app--wide')
 *           bool   $useMap     (load the vendored Leaflet assets)
 *
 * The customer session actor is assumed already established — every customer
 * page calls require_customer() before including this partial.
 */
$navActive = $navActive ?? '';
$shellCustomer = current_customer();

// Header avatar (Phase 7.4 Chunk 1, Decision 77): the signed-in customer's own
// picture via customer/avatar.php, else the generic person icon. Profile / My
// Vehicles have already worked it out ($accountAvatarUrl, possibly null) for
// their account panel; any other page does one primary-key lookup. Never cached
// in the session, so another device's upload / removal shows up on next load.
if (array_key_exists('accountAvatarUrl', get_defined_vars())) {
    $shellAvatarUrl = $accountAvatarUrl;
} else {
    $shellAvatarRow = $shellCustomer !== null
        ? (new \VulcaTrack\Repository\CustomerRepository(vulcatrack_db()))->findById((int) $shellCustomer['id'])
        : null;
    $shellAvatarUrl = $shellAvatarRow !== null
        ? \VulcaTrack\Support\AvatarStore::forApp()->displayUrl($shellAvatarRow['avatar_filename'] ?? null, (int) $shellCustomer['id'])
        : null;
}
$shellName = (string) ($shellCustomer['name'] ?? '');
// The Figma "Tracking" item and notification bell have no destination in
// VulcaTrack (no live tracking, no notifications); request status lives under
// My Bookings. My Vehicles lives under Profile (account navigation,
// customer_account_nav.php) since Phase 7.4b-e1; its URLs are unchanged.
$nav = [
    'dashboard' => ['Home',          '/customer/dashboard.php'],
    'rescue'    => ['Book a Rescue', '/customer/rescue.php'],
    'bookings'  => ['My Bookings',   '/customer/bookings.php'],
    'profile'   => ['Profile',       '/customer/profile.php'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'VulcaTrack') ?> &mdash; VulcaTrack</title>
<link rel="icon" type="image/png" sizes="192x192" href="<?= e(vulcatrack_asset('/assets/img/vulcatrack-favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(vulcatrack_asset('/assets/css/app.css')) ?>">
<script defer src="<?= e(vulcatrack_asset('/assets/js/customer-nav.js')) ?>"></script>
<?php if (!empty($useMap)): ?>
<link rel="stylesheet" href="<?= e(vulcatrack_asset('/assets/lib/leaflet/leaflet.css')) ?>">
<script defer src="<?= e(vulcatrack_asset('/assets/lib/leaflet/leaflet.js')) ?>"></script>
<?php endif; ?>
</head>
<body>
<div class="cust">
<a class="cust-skip" href="#main">Skip to content</a>
<header class="appbar">
  <a class="appbar__brand" href="<?= e(vulcatrack_url('/customer/dashboard.php')) ?>"><img src="<?= e(vulcatrack_asset('/assets/img/vulcatrack-logo.svg')) ?>" alt="VulcaTrack — On-the-Go Vulcanizing Services" width="5117" height="1638"></a>
  <nav class="appnav" aria-label="Customer">
    <?php foreach ($nav as $key => [$label, $path]): ?>
      <a href="<?= e(vulcatrack_url($path)) ?>"<?= $navActive === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
    <span class="appnav__indicator" aria-hidden="true"></span>
  </nav>
  <div class="appbar__account">
    <a class="appbar__user" href="<?= e(vulcatrack_url('/customer/profile.php')) ?>" aria-label="<?= e($shellName) ?> (Profile)"><?php
      if ($shellAvatarUrl !== null): ?><img class="appbar__avatar" src="<?= e($shellAvatarUrl) ?>" alt="" width="32" height="32"><?php
      else: ?><svg class="appbar__icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><circle cx="12" cy="10" r="3.2"/><path d="M6.3 18.4c1.3-2.2 3.3-3.3 5.7-3.3s4.4 1.1 5.7 3.3"/></svg><?php
      endif; ?><span class="appbar__name"><?= e($shellName) ?></span></a>
    <form class="appbar__logout" method="post" action="<?= e(vulcatrack_url('/logout.php')) ?>">
      <?= \VulcaTrack\Auth\Csrf::field() ?>
      <button type="submit">Log out</button>
    </form>
  </div>
</header>
<main class="app<?= !empty($mainClass) ? ' ' . e($mainClass) : '' ?>" id="main" tabindex="-1">
