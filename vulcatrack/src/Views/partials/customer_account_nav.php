<?php
/**
 * Account (Profile) side panel — Phase 7.4b-e1. The Figma "Account Settings"
 * sidebar: who is signed in + the account sections. Personal Info and Security
 * are sections of profile.php; Your Vehicles is vehicles.php; Log out is the
 * same POST + CSRF logout as the header. No Notifications (not a feature).
 *
 * The portrait is the customer's own uploaded picture (served by
 * customer/avatar.php, Decision 77) or, without one, their initials.
 *
 * Expects:  string $accountName    the signed-in customer's name
 * Optional: string $accountActive  (info|security|vehicles)
 *           string $accountSince   customers.created_at (shown as "Member since")
 *           string $accountAvatarUrl  display URL of the picture; null/absent = initials
 */
$accountActive = $accountActive ?? '';
$accountInitials = '';
foreach (array_slice(preg_split('/\s+/u', trim($accountName), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2) as $word) {
    $accountInitials .= mb_strtoupper(mb_substr($word, 0, 1));
}
$accountSinceTs = !empty($accountSince) ? strtotime((string) $accountSince) : false;
$accountLinks = [
    'info'     => ['Personal Info', '/customer/profile.php',
                   '<rect x="3.5" y="5" width="17" height="14" rx="1"/><circle cx="9" cy="11" r="2"/><path d="M5.8 16c.6-1.5 1.8-2.3 3.2-2.3s2.6.8 3.2 2.3M14.5 10h3.5M14.5 13.5h3.5"/>'],
    'security' => ['Security', '/customer/profile.php#security',
                   '<rect x="5" y="10.5" width="14" height="10" rx="1"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3M12 14.5v2.5"/>'],
    'vehicles' => ['Your Vehicles', '/customer/vehicles.php',
                   '<path d="M5 16V11l2-5h10l2 5v5M5 16h14M5 16v2.5M19 16v2.5M4 11h16"/>'],
];
?>
<aside class="ac-side cu-card" aria-label="Account">
  <div class="ac-id">
    <?php if (!empty($accountAvatarUrl)): ?>
      <img class="ac-avatar ac-avatar--photo" src="<?= e($accountAvatarUrl) ?>" alt="" width="58" height="58">
    <?php else: ?>
      <span class="ac-avatar" aria-hidden="true"><?= e($accountInitials !== '' ? $accountInitials : '?') ?></span>
    <?php endif; ?>
    <div class="ac-id__text">
      <p class="ac-id__name"><?= e($accountName) ?></p>
      <?php if ($accountSinceTs !== false): ?>
        <p class="ac-id__since">Member since <?= e(date('Y', $accountSinceTs)) ?></p>
      <?php endif; ?>
    </div>
  </div>
  <nav class="ac-nav" aria-label="Account sections">
    <ul>
      <?php foreach ($accountLinks as $key => [$label, $path, $icon]): ?>
        <li><a href="<?= e(vulcatrack_url($path)) ?>"<?= $accountActive === $key ? ' class="is-active" aria-current="page"' : '' ?>><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg><?= e($label) ?></a></li>
      <?php endforeach; ?>
      <li>
        <form method="post" action="<?= e(vulcatrack_url('/logout.php')) ?>">
          <?= \VulcaTrack\Auth\Csrf::field() ?>
          <button type="submit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4.5H5v15h5M14.5 8l4 4-4 4M18.5 12H9.5"/></svg>Log out</button>
        </form>
      </li>
    </ul>
  </nav>
</aside>
