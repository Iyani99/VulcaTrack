<?php
/** Admin-selected Product promotions. The slow-moving list is only a creation gate. */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\ProductPromotionRepository;
use VulcaTrack\Service\ProductPromotionService;
use VulcaTrack\Support\Money;
use VulcaTrack\Support\PromotionLifecycle;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$pdo = vulcatrack_db();
$items = new ItemRepository($pdo);
$promotions = new ProductPromotionRepository($pdo);
$service = new ProductPromotionService($pdo);
$today = date('Y-m-d');
$error = null;
$success = is_string($_GET['saved'] ?? null) ? $_GET['saved'] : '';
$input = [
    'item_id' => is_string($_GET['product'] ?? null) ? $_GET['product'] : '',
    'sale_price' => '', 'starts_on' => '', 'ends_on' => '',
    'unit_limit' => '', 'note' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['_action'] ?? null) ? $_POST['_action'] : '';
    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            if ($action === 'create') {
                foreach ($input as $key => $value) {
                    $input[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
                }
                $itemId = filter_var($input['item_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($itemId === false) { throw new InvalidArgumentException('Choose a slow-moving Product.'); }
                $service->create((int) $itemId, $input['sale_price'], $input['starts_on'], $input['ends_on'],
                    $input['unit_limit'], $input['note'], (int) $admin['id']);
                header('Location: ' . vulcatrack_url('/admin/inventory-promotions.php?saved=created'));
                exit;
            }
            if ($action === 'cancel') {
                $id = filter_var($_POST['promotion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) { throw new InvalidArgumentException('Choose a valid promotion.'); }
                $service->cancel((int) $id, (int) $admin['id']);
                header('Location: ' . vulcatrack_url('/admin/inventory-promotions.php?saved=cancelled'));
                exit;
            }
            throw new InvalidArgumentException('Choose a valid promotion action.');
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('VulcaTrack promotion action failed: ' . get_class($e) . ': ' . $e->getMessage());
            $error = 'The promotion could not be saved. Please try again.';
        }
    }
}

// One source for slow-moving eligibility. Open promotions are tracked separately.
$candidates = $items->slowMovingProducts(date('Y-m-d H:i:s'));
$all = $promotions->listAll();
$open = [];
$history = [];
$openItems = [];
foreach ($all as $promotion) {
    $promotion['status'] = PromotionLifecycle::status($promotion, $today);
    if (in_array($promotion['status'], ['scheduled', 'active'], true)) {
        $open[] = $promotion;
        $openItems[$promotion['item_id']] = true;
    } else {
        $history[] = $promotion;
    }
}
$eligible = [];
foreach ($candidates as $candidate) {
    if (isset($openItems[$candidate['item_id']])) { continue; }
    $candidate['regular_price_centavos'] = (int) $items->findById($candidate['item_id'])['price_centavos'];
    $eligible[$candidate['item_id']] = $candidate;
}
$selectedId = filter_var($input['item_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selected = $selectedId === false ? null : ($eligible[(int) $selectedId] ?? null);
$displayDate = static fn (string $day): string => (new DateTimeImmutable($day))->format('F j, Y');

$pageTitle = 'Inventory Promotions';
$navActive = 'inventory';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead"><div><h1>Inventory Promotions</h1>
  <p class="pagehead__meta">Choose when to put a slow-moving Product on sale. Prices never change automatically.</p>
</div></div>
<?php $inventoryActive = 'promotions'; require __DIR__ . '/../src/Views/partials/inventory_nav.php'; ?>
<?php if ($success === 'created'): ?><p class="notice" role="status">Promotion created.</p><?php endif; ?>
<?php if ($success === 'cancelled'): ?><p class="notice" role="status">Promotion cancelled. Its history is preserved.</p><?php endif; ?>
<?php if ($error !== null): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>

<section class="panel promotion-panel" aria-labelledby="promo-candidates">
  <header class="panel__head"><h2 id="promo-candidates">Slow-moving candidates</h2></header>
  <?php if (!$eligible): ?><p class="panel__note muted">No slow-moving Products are currently available for a new promotion.</p>
  <?php else: ?>
    <div class="table-scroll"><table class="datatable mobile-records"><thead><tr>
      <th>Product</th><th>Shop stock</th><th>Regular price</th><th>Threshold</th><th>Last Sale</th><th>Action</th>
    </tr></thead><tbody>
    <?php foreach ($eligible as $candidate): ?>
      <tr><td data-label="Product" data-record-primary><?= e($candidate['item_name']) ?></td>
        <td data-label="Shop stock"><?= (int) $candidate['stock_quantity'] ?></td>
        <td data-label="Regular price">&#8369;<?= e(Money::formatDisplay($candidate['regular_price_centavos'])) ?></td>
        <td data-label="Threshold"><?= (int) $candidate['effective_days'] ?> days</td>
        <td data-label="Last Sale"><?= $candidate['last_sale_at'] === null ? 'Never sold' : e((new DateTimeImmutable($candidate['last_sale_at']))->format('M j, Y')) ?></td>
        <td data-label="Action" data-record-actions><a class="btnlink btnlink--outline promotion-action" href="<?= e(vulcatrack_url('/admin/inventory-promotions.php?product=' . (int) $candidate['item_id'] . '#promotion-form')) ?>">Put on Sale</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<?php if ($selected !== null): ?>
<section class="card promotion-form" id="promotion-form" aria-labelledby="promotion-form-title">
  <h2 id="promotion-form-title">Put <?= e($selected['item_name']) ?> on sale</h2>
  <p class="muted">Regular price: &#8369;<?= e(Money::formatDisplay($selected['regular_price_centavos'])) ?> · Stock: <?= (int) $selected['stock_quantity'] ?>. A unit limit controls sale pricing only; stock is not reserved.</p>
  <p class="muted">Leave End date blank to keep the promotion active until cancellation or the sale-priced unit limit is reached.</p>
  <form method="post" action="<?= e(vulcatrack_url('/admin/inventory-promotions.php?product=' . (int) $selected['item_id'] . '#promotion-form')) ?>" class="promotion-form__grid" novalidate>
    <?= Csrf::field() ?><input type="hidden" name="_action" value="create"><input type="hidden" name="item_id" value="<?= (int) $selected['item_id'] ?>">
    <label>Sale price (&#8369;)<input type="text" name="sale_price" inputmode="decimal" required value="<?= e($input['sale_price']) ?>" placeholder="1550.00"></label>
    <label>Start date <span class="promotion-form__hint">(optional; defaults to today)</span><input type="date" name="starts_on" value="<?= e($input['starts_on']) ?>"></label>
    <label>End date <span class="promotion-form__hint">(optional; leave blank for no automatic end date)</span><input type="date" name="ends_on" value="<?= e($input['ends_on']) ?>"></label>
    <label>Sale-priced units <span class="muted">(optional)</span><input type="number" name="unit_limit" min="1" max="<?= (int) $selected['stock_quantity'] ?>" step="1" value="<?= e($input['unit_limit']) ?>"></label>
    <label class="promotion-form__wide">Note <span class="muted">(optional)</span><textarea name="note" maxlength="500" rows="2"><?= e($input['note']) ?></textarea></label>
    <div class="promotion-form__wide"><button type="submit" class="btnlink">Create promotion</button></div>
  </form>
</section>
<?php endif; ?>

<section class="panel promotion-panel" aria-labelledby="promo-open">
  <header class="panel__head"><h2 id="promo-open">Scheduled / Active</h2></header>
  <?php if (!$open): ?><p class="panel__note muted">No open promotions.</p><?php else: ?>
    <div class="table-scroll"><table class="datatable mobile-records"><thead><tr>
      <th>Product</th><th>Regular now</th><th>Sale price</th><th>Dates</th><th>Sale-priced units</th><th>Status</th><th>Action</th>
    </tr></thead><tbody>
    <?php foreach ($open as $promotion): ?>
      <tr><td data-label="Product" data-record-primary><?= e($promotion['item_name']) ?></td>
        <td data-label="Regular now">&#8369;<?= e(Money::formatDisplay($promotion['current_regular_price_centavos'])) ?></td>
        <td data-label="Sale price">&#8369;<?= e(Money::formatDisplay($promotion['sale_price_centavos'])) ?></td>
        <td data-label="Dates"><?= e($displayDate($promotion['starts_on'])) ?><?= $promotion['ends_on'] === null ? ' · No automatic end date' : ' through ' . e($displayDate($promotion['ends_on'])) ?></td>
        <td data-label="Sale-priced units"><?= $promotion['unit_limit'] === null ? 'No cap' : (int) $promotion['units_consumed'] . ' used · ' . (int) ($promotion['unit_limit'] - $promotion['units_consumed']) . ' left of ' . (int) $promotion['unit_limit'] ?></td>
        <td data-label="Status" data-record-status><span class="badge badge--<?= e($promotion['status']) ?>"><?= e($promotion['status'] === 'active' ? 'ACTIVE' : ucfirst($promotion['status'])) ?></span></td>
        <td data-label="Action" data-record-actions><form method="post" action="<?= e(vulcatrack_url('/admin/inventory-promotions.php')) ?>">
          <?= Csrf::field() ?><input type="hidden" name="_action" value="cancel"><input type="hidden" name="promotion_id" value="<?= (int) $promotion['promotion_id'] ?>">
          <button type="submit" class="secondary promotion-action" onclick="return confirm('Cancel this promotion? Its history will be kept.');">Cancel</button>
        </form></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="panel promotion-panel" aria-labelledby="promo-history">
  <header class="panel__head"><h2 id="promo-history">Promotion history</h2></header>
  <?php if (!$history): ?><p class="panel__note muted">No ended or cancelled promotions yet.</p><?php else: ?>
    <div class="table-scroll"><table class="datatable mobile-records"><thead><tr>
      <th>Product</th><th>Sale price</th><th>Regular at creation</th><th>Dates</th><th>Sale-priced units</th><th>Created by</th><th>Note</th><th>Status</th>
    </tr></thead><tbody>
    <?php foreach ($history as $promotion): ?>
      <tr><td data-label="Product" data-record-primary><?= e($promotion['item_name']) ?></td>
        <td data-label="Sale price">&#8369;<?= e(Money::formatDisplay($promotion['sale_price_centavos'])) ?></td>
        <td data-label="Regular at creation">&#8369;<?= e(Money::formatDisplay($promotion['regular_price_snapshot_centavos'])) ?></td>
        <td data-label="Dates"><?= e($displayDate($promotion['starts_on'])) ?><?= $promotion['ends_on'] === null ? ' · No automatic end date' : ' through ' . e($displayDate($promotion['ends_on'])) ?></td>
        <td data-label="Sale-priced units"><?= (int) $promotion['units_consumed'] ?><?= $promotion['unit_limit'] === null ? ' (no cap)' : ' / ' . (int) $promotion['unit_limit'] ?></td>
        <td data-label="Created by"><?= e($promotion['created_by_name']) ?></td>
        <td data-label="Note"><?= $promotion['note'] === null ? '—' : e($promotion['note']) ?></td>
        <td data-label="Status" data-record-status><span class="badge badge--<?= e($promotion['status']) ?>"><?= e(ucfirst($promotion['status'])) ?></span>
          <?php if ($promotion['cancelled_at'] !== null): ?><small class="promotion-cancelled-by">By <?= e($promotion['cancelled_by_name']) ?> · <?= e((new DateTimeImmutable($promotion['cancelled_at']))->format('F j, Y g:i A')) ?></small><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
