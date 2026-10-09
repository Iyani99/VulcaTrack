<?php
/**
 * Shell for the customer Log in / Sign up screens (Phase 7.4b-b: Customer
 * auth Figma). A white page with a top-right back action, the centered
 * VulcaTrack brand and a bordered card that the page fills. Every rule is
 * scoped under .cauth (inside the .cust customer theme). The admin login
 * (src/Views/admin_login.php) uses this same shell since the Phase 7.4 Chunk 1
 * redesign — presentation only; its form, route and session stay separate.
 *
 * Expects: string $pageTitle
 *          array  $backLink  [label, path] for the top-right action
 */
[$backLabel, $backPath] = $backLink;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'VulcaTrack') ?> &mdash; VulcaTrack</title>
<link rel="icon" type="image/png" sizes="192x192" href="<?= e(vulcatrack_asset('/assets/img/vulcatrack-favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(vulcatrack_asset('/assets/css/app.css')) ?>">
</head>
<body>
<div class="cust cauth">
<div class="cauth-top">
  <a class="cauth-back" href="<?= e(vulcatrack_url($backPath)) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5 4 11l6 6M4.5 11H14a6 6 0 0 1 6 6v2"/></svg><?= e($backLabel) ?></a>
</div>
<main class="cauth-main" id="main">
  <a class="cauth-brand" href="<?= e(vulcatrack_url('/')) ?>">
    <img src="<?= e(vulcatrack_asset('/assets/img/vulcatrack-logo.svg')) ?>" alt="VulcaTrack — On-the-Go Vulcanizing Services" width="5117" height="1638">
  </a>
  <section class="cauth-card<?= !empty($customerLogin) || !empty($adminLogin) ? ' cauth-card--login' : '' ?>" aria-labelledby="cauth-title">
