<?php
/**
 * Shell for the signed-in admin area. Mirrors the customer shell
 * (src/Views/partials/customer_top.php) — same appbar / appnav / logout markup
 * and the same app.css classes.
 *
 * Expects:  string $pageTitle
 * Optional: string $navActive  (dashboard|pos|sales|inventory|tiremen|rescue)
 *           array  $admin       (session actor; only used by the page body)
 *           string $bodyClass   (page-specific <body> class, e.g. for print styles)
 *           bool   $useMap      (load the vendored Leaflet assets — map pages only)
 *
 * The admin session actor is assumed already established — every admin page
 * calls require_admin() before including this partial.
 */
$navActive = $navActive ?? '';
$nav = [
    'dashboard' => ['Dashboard', '/admin/index.php'],
    'pos'       => ['POS',       '/admin/pos.php'],
    'sales'     => ['Sales History', '/admin/sales.php'],
    'inventory' => ['Inventory', '/admin/inventory.php'],
    'tiremen'   => ['Tiremen',   '/admin/tiremen.php'],
    'rescue'    => ['Rescue',    '/admin/rescue.php'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Admin') ?> &mdash; VulcaTrack</title>
<link rel="stylesheet" href="<?= e(vulcatrack_asset('/assets/css/app.css')) ?>">
<?php if (!empty($useMap)): ?>
<link rel="stylesheet" href="<?= e(vulcatrack_asset('/assets/lib/leaflet/leaflet.css')) ?>">
<script defer src="<?= e(vulcatrack_asset('/assets/lib/leaflet/leaflet.js')) ?>"></script>
<?php endif; ?>
</head>
<body<?= !empty($bodyClass) ? ' class="' . e($bodyClass) . '"' : '' ?>>
<header class="appbar">
  <a class="appbar__brand" href="<?= e(vulcatrack_url('/admin/index.php')) ?>">VulcaTrack Admin</a>
  <nav class="appnav">
    <?php foreach ($nav as $key => [$label, $path]): ?>
      <a href="<?= e(vulcatrack_url($path)) ?>"<?= $navActive === $key ? ' class="is-active"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="appbar__logout" method="post" action="<?= e(vulcatrack_url('/admin/logout.php')) ?>">
    <?= \VulcaTrack\Auth\Csrf::field() ?>
    <button type="submit">Log out</button>
  </form>
</header>
<main class="app">
