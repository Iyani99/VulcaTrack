<?php
/**
 * Shell for the signed-in customer area (Phase 7.4b-a: Customer Figma header).
 * A white top bar — brand, the customer nav, the signed-in customer's name and
 * the POST + CSRF logout. Every customer-theme rule in app.css is scoped under
 * the .cust wrapper, so admin and guest (login / register) screens are
 * unaffected. On narrow screens the nav wraps onto its own row (CSS only).
 *
 * Expects:  string $pageTitle
 * Optional: string $navActive  (dashboard|rescue|bookings|vehicles|profile)
 *           string $mainClass  (extra class on <main>, e.g. 'app--wide')
 *           bool   $useMap     (load the vendored Leaflet assets)
 *
 * The customer session actor is assumed already established — every customer
 * page calls require_customer() before including this partial.
 */
$navActive = $navActive ?? '';
$shellCustomer = current_customer();
// The Figma "Tracking" item and notification bell have no destination in
// VulcaTrack (no live tracking, no notifications); request status lives under
// My Bookings. My Vehicles is not in the Figma but is a required existing page.
$nav = [
    'dashboard' => ['Home',          '/customer/dashboard.php'],
    'rescue'    => ['Book a Rescue', '/customer/rescue.php'],
    'bookings'  => ['My Bookings',   '/customer/bookings.php'],
    'vehicles'  => ['My Vehicles',   '/customer/vehicles.php'],
    'profile'   => ['Profile',       '/customer/profile.php'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'VulcaTrack') ?> &mdash; VulcaTrack</title>
<link rel="stylesheet" href="<?= e(vulcatrack_asset('/assets/css/app.css')) ?>">
<?php if (!empty($useMap)): ?>
<link rel="stylesheet" href="<?= e(vulcatrack_asset('/assets/lib/leaflet/leaflet.css')) ?>">
<script defer src="<?= e(vulcatrack_asset('/assets/lib/leaflet/leaflet.js')) ?>"></script>
<?php endif; ?>
</head>
<body>
<div class="cust">
<a class="cust-skip" href="#main">Skip to content</a>
<header class="appbar">
  <a class="appbar__brand" href="<?= e(vulcatrack_url('/customer/dashboard.php')) ?>">Vulca<span class="cust-red">Track</span></a>
  <nav class="appnav" aria-label="Customer">
    <?php foreach ($nav as $key => [$label, $path]): ?>
      <a href="<?= e(vulcatrack_url($path)) ?>"<?= $navActive === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="appbar__account">
    <span class="appbar__user"><svg class="appbar__icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><circle cx="12" cy="10" r="3.2"/><path d="M6.3 18.4c1.3-2.2 3.3-3.3 5.7-3.3s4.4 1.1 5.7 3.3"/></svg><span class="appbar__name"><?= e((string) ($shellCustomer['name'] ?? '')) ?></span></span>
    <form class="appbar__logout" method="post" action="<?= e(vulcatrack_url('/logout.php')) ?>">
      <?= \VulcaTrack\Auth\Csrf::field() ?>
      <button type="submit">Log out</button>
    </form>
  </div>
</header>
<main class="app<?= !empty($mainClass) ? ' ' . e($mainClass) : '' ?>" id="main" tabindex="-1">
