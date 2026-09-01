<?php
/**
 * Shared page head for the authentication screens.
 * Expects: string $pageTitle
 */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'VulcaTrack') ?> &mdash; VulcaTrack</title>
<link rel="stylesheet" href="<?= e(vulcatrack_url('/assets/css/app.css')) ?>">
</head>
<body>
<main class="auth">
<p class="brand">VulcaTrack</p>
