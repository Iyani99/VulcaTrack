<?php
/**
 * Admin — Tiremen (Phase 6, Chunk 6.1).
 *
 * The service providers who perform OTG jobs (Decisions 22-26). A Tireman is a
 * record only — no login, no dashboard. Browsing uses a GET status filter
 * (active / inactive / all). Mutations here are limited to activate /
 * deactivate: POST + CSRF, then redirect back to the same filter (PRG) with a
 * one-time session flash. Add / edit live on tireman-edit.php. All persistence
 * goes through TiremanRepository; there is no hard delete.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\TiremanRepository;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$repo  = new TiremanRepository(vulcatrack_db());

/** The status filter from GET (or carried through a POST); anything unknown means 'active'. */
function tireman_status(array $src): string
{
    $status = (string) ($src['status'] ?? '');
    return in_array($status, ['inactive', 'all'], true) ? $status : 'active';
}

function tireman_list_url(string $status): string
{
    return vulcatrack_url('/admin/tiremen.php' . ($status === 'active' ? '' : '?status=' . $status));
}

// --- activate / deactivate (POST only, CSRF, then redirect) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = tireman_status($_POST);
    $action = (string) ($_POST['_action'] ?? '');
    // A positive whole number that fits tiremen.tireman_id (signed INT).
    $rawId  = $_POST['tireman_id'] ?? '';
    $target = (is_string($rawId) && preg_match('/^[1-9]\d{0,9}$/', $rawId) && (int) $rawId <= 2147483647)
        ? (int) $rawId
        : 0;

    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $_SESSION['tireman_flash'][] = ['error', 'Your session expired. Please try again.'];
    } elseif ($target > 0 && in_array($action, ['activate', 'deactivate'], true)
        && ($tireman = $repo->findById($target)) !== null) {
        $repo->setActive($target, $action === 'activate');
        $_SESSION['tireman_flash'][] = ['notice', $tireman['name'] . ($action === 'activate' ? ' activated.' : ' deactivated.')];
    } else {
        $_SESSION['tireman_flash'][] = ['error', 'That action could not be completed. Please try again.'];
    }
    header('Location: ' . tireman_list_url($status));
    exit;
}

$flashes = is_array($_SESSION['tireman_flash'] ?? null) ? $_SESSION['tireman_flash'] : [];
unset($_SESSION['tireman_flash']);

$status   = tireman_status($_GET);
$tiremen  = $repo->list($status === 'all' ? null : $status === 'active');

$pageTitle = 'Tiremen';
$navActive = 'tiremen';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <h1>Tiremen</h1>
  <a class="btnlink" href="<?= e(vulcatrack_url('/admin/tireman-edit.php')) ?>">Add Tireman</a>
</div>
<p class="muted">The people who perform On-the-Go services. Only active Tiremen can be given new assignments.</p>

<?php foreach ($flashes as [$type, $message]): ?>
  <p class="<?= $type === 'error' ? 'error' : 'notice' ?>"><?= e($message) ?></p>
<?php endforeach; ?>

<form class="filterbar" method="get" action="<?= e(vulcatrack_url('/admin/tiremen.php')) ?>">
  <label>Status
    <select name="status">
      <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
      <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option>
      <option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All</option>
    </select>
  </label>
  <button type="submit">Filter</button>
</form>

<?php $n = count($tiremen); ?>
<p class="muted"><?= $n ?> <?= $n === 1 ? 'Tireman' : 'Tiremen' ?>.</p>

<?php if (!$tiremen): ?>
  <p class="muted">No Tiremen to show.</p>
<?php else: ?>
  <div class="table-scroll">
  <table class="datatable">
    <thead>
      <tr><th>Name</th><th>Contact number</th><th>State</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($tiremen as $row): ?>
      <?php $isActive = $row['is_active'] === 1; ?>
      <tr>
        <td><?= e($row['name']) ?></td>
        <td><?= e($row['contact_number']) ?></td>
        <td>
          <span class="badge badge--<?= $isActive ? 'active' : 'inactive' ?>">
            <?= $isActive ? 'Active' : 'Inactive' ?>
          </span>
        </td>
        <td class="rowactions">
          <a href="<?= e(vulcatrack_url('/admin/tireman-edit.php?id=' . (int) $row['tireman_id'])) ?>">Edit</a>
          <form method="post" action="<?= e(vulcatrack_url('/admin/tiremen.php')) ?>">
            <?= Csrf::field() ?>
            <?php if ($status !== 'active'): ?>
              <input type="hidden" name="status" value="<?= e($status) ?>">
            <?php endif; ?>
            <input type="hidden" name="tireman_id" value="<?= (int) $row['tireman_id'] ?>">
            <?php if ($isActive): ?>
              <input type="hidden" name="_action" value="deactivate">
              <button type="submit" class="linklike"
                      onclick="return confirm('Deactivate this Tireman? They cannot be given new assignments but stay on past requests.');">Deactivate</button>
            <?php else: ?>
              <input type="hidden" name="_action" value="activate">
              <button type="submit" class="linklike">Activate</button>
            <?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
