<?php
/**
 * Shell for the signed-in admin area (Phase 7.3a): a dark left sidebar — brand,
 * the admin nav, the signed-in admin's name and the POST + CSRF logout — beside
 * the page content. Every shell / admin-theme rule in app.css is scoped under
 * body.admin, so the customer and auth screens (which share the base app.css
 * rules) are unaffected. Below ~60rem the sidebar becomes a top bar (CSS only).
 *
 * Expects:  string $pageTitle
 * Optional: string $navActive  (dashboard|inventory|pos|sales|rescue|tiremen|reports;
 *                               accounts = the footer Admin accounts link, no nav item)
 *           string $bodyClass   (extra page-specific <body> class, e.g. for print styles)
 *           bool   $useMap      (load the vendored Leaflet assets — map pages only)
 *
 * The admin session actor is assumed already established — every admin page
 * calls require_admin() before including this partial. The sidebar reads the
 * name from the session itself, so it never depends on a page variable.
 */
$navActive  = $navActive ?? '';
$shellAdmin = current_admin();
// key => [label, path, inline SVG icon body (trusted constant markup)]
$nav = [
    'dashboard' => ['Dashboard',     '/admin/index.php',     '<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>'],
    'inventory' => ['Inventory',     '/admin/inventory.php', '<rect x="3" y="4" width="18" height="4"/><path d="M5 8v12h14V8M10 12h4"/>'],
    'pos'       => ['POS',           '/admin/pos.php',       '<path d="M6 11V4h9v7"/><rect x="3" y="11" width="18" height="9"/><path d="M9 7.5h3M7 15h2m2.5 0h2m2.5 0h2"/>'],
    'sales'     => ['Sales History', '/admin/sales.php',     '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>'],
    'rescue'    => ['Rescue',        '/admin/rescue.php',    '<path d="M12 21s-7-7.2-7-12a7 7 0 0 1 14 0c0 4.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>'],
    'tiremen'   => ['Tiremen',       '/admin/tiremen.php',   '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>'],
    'reports'   => ['Reports',       '/admin/reports.php',   '<rect x="3" y="3" width="18" height="18"/><path d="M8 17v-4M12 17V8M16 17v-6"/>'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Admin') ?> &mdash; VulcaTrack</title>
<link rel="stylesheet" href="<?= e(vulcatrack_asset('/assets/css/app.css')) ?>">
<script src="<?= e(vulcatrack_asset('/assets/js/admin-nav.js')) ?>"></script>
<?php if (!empty($useMap)): ?>
<link rel="stylesheet" href="<?= e(vulcatrack_asset('/assets/lib/leaflet/leaflet.css')) ?>">
<script defer src="<?= e(vulcatrack_asset('/assets/lib/leaflet/leaflet.js')) ?>"></script>
<?php endif; ?>
</head>
<body class="admin<?= !empty($bodyClass) ? ' ' . e($bodyClass) : '' ?>">
<a class="adm-skip" href="#main">Skip to content</a>
<div class="adm">
<aside class="adm-side">
<div class="adm-side__inner">
  <a class="adm-brand" href="<?= e(vulcatrack_url('/admin/index.php')) ?>">
    <svg class="adm-brand__pin" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>
    <span class="adm-brand__text">
      <span class="adm-brand__name">Vulca<span class="adm-red">Track</span></span>
      <span class="adm-brand__sub">Admin Terminal</span>
    </span>
  </a>
  <nav class="adm-nav" aria-label="Admin">
    <?php foreach ($nav as $key => [$label, $path, $icon]): ?>
      <a href="<?= e(vulcatrack_url($path)) ?>"<?= $navActive === $key ? ' class="is-active" aria-current="page"' : '' ?>><svg class="adm-nav__icon" viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="adm-side__foot">
    <p class="adm-user"><span class="adm-user__label">Signed in as</span> <span class="adm-user__name"><?= e((string) ($shellAdmin['name'] ?? '')) ?></span></p>
    <?php /* Decision 79: account admin lives with the signed-in identity, not in the operational nav */ ?>
    <a class="adm-accounts<?= $navActive === 'accounts' ? ' is-active" aria-current="page' : '' ?>" href="<?= e(vulcatrack_url('/admin/accounts.php')) ?>">Admin accounts</a>
    <form class="adm-logout" method="post" action="<?= e(vulcatrack_url('/admin/logout.php')) ?>">
      <?= \VulcaTrack\Auth\Csrf::field() ?>
      <button type="submit">Log out</button>
    </form>
  </div>
</div>
</aside>
<main class="app adm-main" id="main" tabindex="-1">
