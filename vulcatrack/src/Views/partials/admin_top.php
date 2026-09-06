<?php
/**
 * Shell for the signed-in admin area. Mirrors the customer shell
 * (src/Views/partials/customer_top.php) — same appbar / appnav / logout markup
 * and the same app.css classes.
 *
 * Expects:  string $pageTitle
 * Optional: string $navActive  (dashboard|pos|inventory)
 *           array  $admin       (session actor; only used by the page body)
 *
 * The admin session actor is assumed already established — every admin page
 * calls require_admin() before including this partial.
 */
$navActive = $navActive ?? '';
$nav = [
    'dashboard' => ['Dashboard', '/admin/index.php'],
    'pos'       => ['POS',       '/admin/pos.php'],
    'inventory' => ['Inventory', '/admin/inventory.php'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Admin') ?> &mdash; VulcaTrack</title>
<link rel="stylesheet" href="<?= e(vulcatrack_asset('/assets/css/app.css')) ?>">
</head>
<body>
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
