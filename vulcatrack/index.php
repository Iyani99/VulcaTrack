<?php
/**
 * VulcaTrack — public landing page (Phase 7.2).
 *
 * Read-only front door: what the system does and the ways in (customer login /
 * registration, Book a Rescue, Admin Portal). Layout follows the approved Figma
 * landing frame; the copy follows the approved scope, NOT the Figma wording —
 * there is no live Tireman tracking: a rescue request shows its status, the
 * assigned Tireman and an ETA computed once when it is submitted (Decisions
 * 32/48). Shop details come from config/shop.php; no contact details are made
 * up. Styles are page-scoped (`body.lp`, `.lp-*` in app.css).
 *
 * "Book a Rescue" links to customer/rescue.php, whose customer guard already
 * sends a guest to the customer login.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';

$shop     = require VULCATRACK_ROOT . '/config/shop.php';
$customer = current_customer();
$admin    = current_admin();

/** Escaped app URL for an href. */
function lp_url(string $path): string
{
    return e(vulcatrack_url($path));
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VulcaTrack &mdash; Sales and Inventory with On-the-Go Services</title>
<link rel="stylesheet" href="<?= e(vulcatrack_asset('/assets/css/app.css')) ?>">
</head>
<body class="lp">

<header class="lp-header">
  <div class="lp-wrap lp-header__inner">
    <a class="lp-brand" href="<?= lp_url('/') ?>">
      <svg class="lp-brand__pin" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>
      <span class="lp-brand__text">
        <span class="lp-brand__name">Vulca<span class="lp-red">Track</span></span>
        <span class="lp-brand__sub"><?= e($shop['name']) ?></span>
      </span>
    </a>
    <nav class="lp-nav" aria-label="Page sections">
      <a href="#features">Features</a>
      <a href="#how-it-works">How It Works</a>
    </nav>
    <div class="lp-actions">
      <?php if ($customer !== null): ?>
        <a class="lp-btn lp-btn--outline" href="<?= lp_url('/customer/dashboard.php') ?>">My Dashboard</a>
      <?php elseif ($admin !== null): ?>
        <a class="lp-btn lp-btn--outline" href="<?= lp_url('/admin/index.php') ?>">Admin Dashboard</a>
      <?php else: ?>
        <a class="lp-btn lp-btn--outline" href="<?= lp_url('/login.php') ?>">Login</a>
      <?php endif; ?>
      <a class="lp-btn lp-btn--red" href="<?= lp_url('/customer/rescue.php') ?>">Book a Rescue</a>
    </div>
  </div>
</header>

<main>
  <section class="lp-hero">
    <div class="lp-wrap lp-hero__inner">
      <div class="lp-hero__text">
        <h1>Sales and Inventory <span class="lp-red">with On-the-Go Services</span></h1>
        <p class="lp-lead">
          Vulcanizing and tire services from <?= e($shop['name']) ?> &mdash; at the shop,
          or out on the road when you need help. Request a roadside rescue and follow
          its status, while the shop keeps its sales and stock in order.
        </p>
        <div class="lp-cta">
          <a class="lp-btn lp-btn--red lp-btn--lg" href="<?= lp_url('/customer/rescue.php') ?>">Book a Rescue</a>
          <a class="lp-btn lp-btn--dark lp-btn--lg" href="<?= lp_url('/admin/login.php') ?>">Admin Portal</a>
        </div>
        <p class="lp-small">New customer? <a href="<?= lp_url('/register.php') ?>">Create an account</a> to book a rescue.</p>
      </div>

      <figure class="lp-hero__visual">
        <svg viewBox="0 0 360 230" role="img" aria-labelledby="lp-map-title">
          <title id="lp-map-title">Illustration: the shop and a customer's location, joined by a straight dashed line</title>
          <rect width="360" height="230" fill="#ececec"/>
          <g fill="#fafafa">
            <rect x="12" y="12" width="96" height="62"/><rect x="120" y="12" width="110" height="62"/><rect x="242" y="12" width="106" height="62"/>
            <rect x="12" y="86" width="96" height="58"/><rect x="120" y="86" width="110" height="58"/><rect x="242" y="86" width="106" height="58"/>
            <rect x="12" y="156" width="96" height="62"/><rect x="120" y="156" width="110" height="62"/><rect x="242" y="156" width="106" height="62"/>
          </g>
          <path d="M72 176 C 150 150, 210 110, 292 52" fill="none" stroke="#111" stroke-width="2.5" stroke-dasharray="7 6"/>
          <circle cx="72" cy="176" r="15" fill="#e31b23" opacity=".18"/>
          <path d="M72 150a10 10 0 0 0-10 10c0 7 10 17 10 17s10-10 10-17a10 10 0 0 0-10-10z" fill="#e31b23"/>
          <path d="M292 26a10 10 0 0 0-10 10c0 7 10 17 10 17s10-10 10-17a10 10 0 0 0-10-10z" fill="#111"/>
          <text x="58" y="205" font-size="12" font-weight="700" fill="#111">You</text>
          <text x="308" y="42" font-size="12" font-weight="700" fill="#111">Shop</text>
        </svg>
        <figcaption>
          <strong><?= e($shop['name']) ?></strong>
          <span><?= e($shop['address']) ?></span>
        </figcaption>
      </figure>
    </div>
  </section>

  <section id="features" class="lp-section">
    <div class="lp-wrap">
      <h2 class="lp-section__title">Built for the Shop and the Road</h2>
      <p class="lp-section__sub">What VulcaTrack does for customers and for the shop.</p>

      <div class="lp-features">
        <article class="lp-feature">
          <span class="lp-feature__icon lp-feature__icon--red" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M22 19.6 13.3 11a6 6 0 0 0-7.9-7.6l4.1 4.1-2.1 2.1-4.1-4.1A6 6 0 0 0 11 13.3l8.6 8.7a1.7 1.7 0 0 0 2.4 0 1.7 1.7 0 0 0 0-2.4z"/></svg>
          </span>
          <h3>On-the-Go Rescue</h3>
          <p>Stuck with a flat? Send a rescue request with your saved vehicle, contact number
            and location &mdash; from your browser or by searching a landmark &mdash; and
            confirm the spot on the map.</p>
        </article>

        <article class="lp-feature">
          <span class="lp-feature__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M3 3h18v5H3zm1 6h16v12H4zm5 3v2h6v-2z"/></svg>
          </span>
          <h3>Sales &amp; Inventory</h3>
          <p>Products and services in one catalogue. The shop records counter sales through
            the POS, watches product stock with low-stock alerts, and keeps a printable
            summary of every transaction.</p>
        </article>

        <article class="lp-feature">
          <span class="lp-feature__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 10.4 3.6 2.1-1 1.7L11 13V6h2z"/></svg>
          </span>
          <h3>Rescue Status &amp; ETA</h3>
          <p>Check your request at any time: whether it is pending, accepted, rejected or
            completed, which Tireman is assigned, and the estimated arrival time worked out
            once when you booked.</p>
        </article>
      </div>
    </div>
  </section>

  <section id="how-it-works" class="lp-section lp-section--alt">
    <div class="lp-wrap">
      <h2 class="lp-section__title">How It Works</h2>
      <p class="lp-section__sub">Four simple steps to get back on the road.</p>

      <ol class="lp-steps">
        <li class="lp-step">
          <span class="lp-step__num">01</span>
          <h3>Book</h3>
          <p>Sign in and submit an On-the-Go rescue request for your vehicle.</p>
        </li>
        <li class="lp-step">
          <span class="lp-step__num">02</span>
          <h3>Status</h3>
          <p>The shop reviews your request. Follow its status and your estimated arrival time.</p>
        </li>
        <li class="lp-step">
          <span class="lp-step__num">03</span>
          <h3>Service</h3>
          <p>The assigned Tireman comes to your location and handles the roadside repair.</p>
        </li>
        <li class="lp-step">
          <span class="lp-step__num">04</span>
          <h3>Done</h3>
          <p>The request is marked completed and stays in your booking history.</p>
        </li>
      </ol>
    </div>
  </section>
</main>

<footer class="lp-footer">
  <div class="lp-wrap lp-footer__cols">
    <div>
      <h2 class="lp-footer__title">VulcaTrack</h2>
      <p>Sales and Inventory with On-the-Go Services.</p>
    </div>
    <div>
      <h2 class="lp-footer__title">Visit the Shop</h2>
      <p><?= e($shop['name']) ?><br><?= e($shop['address']) ?></p>
    </div>
    <div>
      <h2 class="lp-footer__title">Quick Links</h2>
      <ul class="lp-footer__links">
        <li><a href="<?= lp_url('/login.php') ?>">Customer Login</a></li>
        <li><a href="<?= lp_url('/register.php') ?>">Create an Account</a></li>
        <li><a href="<?= lp_url('/customer/rescue.php') ?>">Book a Rescue</a></li>
        <li><a href="<?= lp_url('/admin/login.php') ?>">Admin Portal</a></li>
      </ul>
    </div>
  </div>
  <p class="lp-footer__copy">&copy; <?= e(date('Y')) ?> <?= e($shop['name']) ?> &middot; VulcaTrack</p>
</footer>

</body>
</html>
