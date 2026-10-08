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
<link rel="stylesheet" href="<?= e(vulcatrack_asset('/assets/css/app.css')) ?>">
</head>
<body>
<div class="cust cauth">
<div class="cauth-top">
  <a class="cauth-back" href="<?= e(vulcatrack_url($backPath)) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5 4 11l6 6M4.5 11H14a6 6 0 0 1 6 6v2"/></svg><?= e($backLabel) ?></a>
</div>
<main class="cauth-main" id="main">
  <a class="cauth-brand" href="<?= e(vulcatrack_url('/')) ?>">
    <svg class="cauth-brand__pin" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>
    <span class="cauth-brand__text">
      <span class="cauth-brand__name">Vulca<span class="cust-red">Track</span></span>
      <span class="cauth-brand__sub">On-the-Go Vulcanizing Services</span>
    </span>
  </a>
  <section class="cauth-card<?= !empty($customerLogin) ? ' cauth-card--customer-login' : '' ?>" aria-labelledby="cauth-title">
