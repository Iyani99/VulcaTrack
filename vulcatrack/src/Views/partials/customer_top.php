<?php
/**
 * Shell for the signed-in customer area.
 * Expects: string $pageTitle
 * Optional: string $navActive  (dashboard|vehicles|rescue|bookings|profile)
 *           array  $customer   (session actor; for the greeting)
 *           bool   $useMap     (load the vendored Leaflet assets)
 */
$navActive = $navActive ?? '';
$nav = [
    'dashboard' => ['Dashboard',      '/customer/dashboard.php'],
    'vehicles'  => ['My Vehicles',    '/customer/vehicles.php'],
    'rescue'    => ['Book a Rescue',  '/customer/rescue.php'],
    'bookings'  => ['My Bookings',    '/customer/bookings.php'],
    'profile'   => ['Profile',        '/customer/profile.php'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'VulcaTrack') ?> &mdash; VulcaTrack</title>
<link rel="stylesheet" href="<?= e(vulcatrack_url('/assets/css/app.css')) ?>">
<?php if (!empty($useMap)): ?>
<link rel="stylesheet" href="<?= e(vulcatrack_url('/assets/lib/leaflet/leaflet.css')) ?>">
<script defer src="<?= e(vulcatrack_url('/assets/lib/leaflet/leaflet.js')) ?>"></script>
<?php endif; ?>
</head>
<body>
<header class="appbar">
  <a class="appbar__brand" href="<?= e(vulcatrack_url('/customer/dashboard.php')) ?>">VulcaTrack</a>
  <nav class="appnav">
    <?php foreach ($nav as $key => [$label, $path]): ?>
      <a href="<?= e(vulcatrack_url($path)) ?>"<?= $navActive === $key ? ' class="is-active"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="appbar__logout" method="post" action="<?= e(vulcatrack_url('/logout.php')) ?>">
    <?= \VulcaTrack\Auth\Csrf::field() ?>
    <button type="submit">Log out</button>
  </form>
</header>
<main class="app">
