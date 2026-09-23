<?php
/**
 * Admin — Point of Sale (Phase 5).
 *
 * The cashier builds a sale in a temporary session cart (PosCart, Decision 57),
 * optionally links an existing customer (Decision 51 — never creates one),
 * enters the cash received, and completes the sale.
 *
 * This page only gathers and checks the cashier's intent. Recording the sale
 * is SaleService::checkout()'s job — it owns the one transaction, re-reads and
 * locks every item, uses the database price, deducts product stock only, and
 * rolls everything back on any failure (Decisions 61–63). The total shown on
 * screen is passed as `expected_total_centavos`, so if a price or the cart
 * changed after the page was displayed the sale is refused rather than
 * recorded at a different amount.
 *
 * Cash received and change are checked here in integer centavos and shown to
 * the cashier once; they are never stored (Decision 54).
 *
 * Every cart change is POST + CSRF and redirects back (PRG) with a one-time
 * session flash. A failed checkout re-renders in place so the cash amount the
 * cashier typed is kept; the cart itself always survives in the session.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Service\PosCart;
use VulcaTrack\Service\PosCartException;
use VulcaTrack\Service\SaleException;
use VulcaTrack\Service\SaleService;
use VulcaTrack\Support\Money;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin     = require_admin();
$pdo       = vulcatrack_db();
$items     = new ItemRepository($pdo);
$customers = new CustomerRepository($pdo);
$cart      = new PosCart($_SESSION);

/** The item-list filters (search + type), read from GET or carried through a POST. */
function pos_filters(array $src): array
{
    $q = trim((string) ($src['q'] ?? ''));
    $type = (string) ($src['type'] ?? '');
    return [
        'q'    => mb_substr($q, 0, 150),
        'type' => in_array($type, ['product', 'service'], true) ? $type : '',
    ];
}

/** POS URL that keeps the current item-list filters. */
function pos_url(array $filters): string
{
    $query = http_build_query(array_filter($filters, fn ($v) => $v !== ''));
    return vulcatrack_url('/admin/pos.php' . ($query !== '' ? '?' . $query : ''));
}

/** Hidden inputs that carry the item-list filters through a POST form. */
function pos_filter_fields(array $filters): string
{
    $html = '';
    foreach ($filters as $name => $value) {
        $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
    }
    return $html;
}

/** A plain run of digits (no sign, no decimals) with at most $maxDigits digits → int, else null. */
function pos_digits($value, int $maxDigits = 9): ?int
{
    if (is_string($value) && preg_match('/^\d{1,' . $maxDigits . '}$/', trim($value))) {
        return (int) trim($value);
    }
    return null;
}

function pos_flash(string $type, string $message): void
{
    $_SESSION['pos_flash'][] = [$type, $message];
}

function pos_peso(int $centavos): string
{
    return '&#8369;' . e(Money::format($centavos));
}

$errors      = [];   // shown on this render (failed checkout)
$tenderInput = '';
$filters     = pos_filters($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);

// ---------------------------------------------------------------------------
// POST: cart changes (PRG) and checkout
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['_action'] ?? '');

    if (!Csrf::check($_POST['_csrf'] ?? null)) {
        pos_flash('error', 'Your session expired. Please try again.');
        header('Location: ' . pos_url($filters));
        exit;
    }

    if ($action !== 'checkout') {
        try {
            switch ($action) {
                case 'add':
                    $itemId   = pos_digits($_POST['item_id'] ?? null) ?? 0;
                    $quantity = pos_digits($_POST['quantity'] ?? null);
                    $item     = $itemId > 0 ? $items->findById($itemId) : null;
                    if ($quantity === null || $quantity < 1 || $quantity > PosCart::MAX_QUANTITY) {
                        throw new PosCartException('Quantity must be a whole number from 1 to ' . PosCart::MAX_QUANTITY . '.');
                    }
                    if ($item === null || (int) $item['is_active'] !== 1) {
                        throw new PosCartException('That item is not available for sale.');
                    }
                    // Friendly early check only — SaleService re-checks under a row lock.
                    $inCart = $cart->quantityOf($itemId);
                    if ($item['item_type'] === 'product' && (int) $item['stock_quantity'] < $inCart + $quantity) {
                        throw new PosCartException(
                            "Not enough stock for \"{$item['item_name']}\" (in stock: " . (int) $item['stock_quantity']
                            . ($inCart > 0 ? ", already in this sale: {$inCart}" : '') . ').'
                        );
                    }
                    $cart->add($itemId, $quantity);
                    pos_flash('notice', "Added {$quantity} × {$item['item_name']}.");
                    break;

                case 'update':
                    $submitted = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
                    $new = [];
                    // Walk the cart, not the POST array: unknown keys are ignored.
                    foreach ($cart->lines() as $itemId => $current) {
                        $q = pos_digits($submitted[$itemId] ?? null);
                        if ($q === null || $q < 1 || $q > PosCart::MAX_QUANTITY) {
                            throw new PosCartException(
                                'Each quantity must be a whole number from 1 to ' . PosCart::MAX_QUANTITY
                                . '. Use Remove to take an item out of the sale.'
                            );
                        }
                        $new[$itemId] = $q;
                    }
                    foreach ($new as $itemId => $q) {   // all-or-nothing
                        $cart->setQuantity($itemId, $q);
                    }
                    pos_flash('notice', 'Quantities updated.');
                    break;

                case 'remove':
                    $cart->remove(pos_digits($_POST['item_id'] ?? null) ?? 0);
                    pos_flash('notice', 'Item removed from the sale.');
                    break;

                case 'clear':
                    $cart->clear();
                    pos_flash('notice', 'Sale cancelled. The cart is empty.');
                    break;

                case 'link_customer':
                    $customerId = pos_digits($_POST['customer_id'] ?? null) ?? 0;
                    $customer   = $customerId > 0 ? $customers->findById($customerId) : null;
                    if ($customer === null) {
                        throw new PosCartException('That customer could not be found.');
                    }
                    $cart->setCustomer((int) $customer['customer_id']);
                    pos_flash('notice', "Linked customer: {$customer['full_name']}.");
                    break;

                case 'unlink_customer':
                    $cart->setCustomer(null);
                    pos_flash('notice', 'The sale is now a walk-in sale.');
                    break;

                default:
                    pos_flash('error', 'That action could not be completed. Please try again.');
            }
        } catch (PosCartException $e) {
            pos_flash('error', $e->getMessage());
        }
        header('Location: ' . pos_url($filters));
        exit;
    }

    // --- checkout ------------------------------------------------------------
    $tenderInput = mb_substr(trim((string) ($_POST['cash_tendered'] ?? '')), 0, 20);

    if ($cart->isEmpty()) {
        $errors[] = 'The sale has no items yet.';
    } else {
        // The quantities on the submitted form must be exactly the cart the
        // displayed total was computed from.
        $submitted = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : null;
        $lines = $cart->lines();
        if ($submitted === null || count($submitted) !== count($lines)) {
            $errors[] = 'This sale was changed in another window. Review it below and try again.';
        } else {
            foreach ($lines as $itemId => $quantity) {
                if (!array_key_exists($itemId, $submitted)) {
                    $errors[] = 'This sale was changed in another window. Review it below and try again.';
                    break;
                }
                if (pos_digits($submitted[$itemId]) !== $quantity) {
                    $errors[] = 'Quantities were edited but not saved. Click "Update quantities", '
                              . 'check the new total, then complete the sale.';
                    break;
                }
            }
        }
    }

    $expected = pos_digits($_POST['expected_total'] ?? null, 11);
    $tender   = Money::tryToCentavos($tenderInput);

    if ($errors === []) {
        if ($expected === null) {
            $errors[] = 'The sale total could not be verified. Reload the page and try again.';
        } elseif ($expected > Money::MAX_CENTAVOS) {
            $errors[] = 'The sale total is larger than this system can record.';
        } elseif ($tender === null) {
            $errors[] = 'Enter the cash received as an amount like 500 or 500.00.';
        } elseif ($tender < $expected) {
            $errors[] = 'Cash received (₱' . Money::format($tender) . ') is less than the total (₱'
                      . Money::format($expected) . ').';
        }
    }

    if ($errors === []) {
        try {
            $result = (new SaleService($pdo))->checkout([
                'admin_id'                => (int) $admin['id'],   // from the session, never the form
                'customer_id'             => $cart->customerId(),
                'lines'                   => $cart->toSaleLines(),
                'expected_total_centavos' => $expected,
            ]);

            $linked = $cart->customerId() !== null ? $customers->findById($cart->customerId()) : null;
            // Shown once, never stored (Decision 54). The service guaranteed
            // total === $expected, and $tender >= $expected was checked above.
            $_SESSION['pos_last_sale'] = [
                'sale_id'        => (int) $result['sale_id'],
                'sale_date'      => (string) $result['sale_date'],
                'total_centavos' => (int) $result['total_centavos'],
                'tender'         => $tender,
                'change'         => $tender - (int) $result['total_centavos'],
                'customer'       => $linked['full_name'] ?? null,
            ];
            $cart->clear();
            header('Location: ' . pos_url($filters));
            exit;
        } catch (SaleException $e) {
            $errors[] = $e->getMessage() . ' Nothing was recorded.';
        } catch (Throwable $e) {
            error_log('VulcaTrack POS checkout failed: ' . get_class($e) . ': ' . $e->getMessage());
            $errors[] = 'The sale could not be recorded because of an unexpected error. Nothing was saved — please try again.';
        }
    }
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
$flashes = is_array($_SESSION['pos_flash'] ?? null) ? $_SESSION['pos_flash'] : [];
unset($_SESSION['pos_flash']);
$lastSale = is_array($_SESSION['pos_last_sale'] ?? null) ? $_SESSION['pos_last_sale'] : null;
unset($_SESSION['pos_last_sale']);

$linkedCustomer = null;
if ($cart->customerId() !== null) {
    $linkedCustomer = $customers->findById($cart->customerId());
    if ($linkedCustomer === null) {
        $cart->setCustomer(null);
        $errors[] = 'The linked customer no longer exists; the sale is now a walk-in sale.';
    }
}

$view = $cart->describe($items);

$customerQuery   = mb_substr(trim((string) ($_GET['cq'] ?? '')), 0, 100);
$customerResults = mb_strlen($customerQuery) >= 2 ? $customers->search($customerQuery, 10) : [];

$catalogFilters = ['active' => true];
if ($filters['q'] !== '')    { $catalogFilters['search'] = $filters['q']; }
if ($filters['type'] !== '') { $catalogFilters['type'] = $filters['type']; }
$catalog = $items->list($catalogFilters);

$pageTitle = 'POS';
$navActive = 'pos';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<h1>Point of Sale</h1>

<?php if ($lastSale !== null): ?>
  <section class="card card--sold" aria-live="polite">
    <h2>Sale #<?= (int) $lastSale['sale_id'] ?> recorded</h2>
    <dl class="pos-summary">
      <div><dt>Total</dt><dd><?= pos_peso((int) $lastSale['total_centavos']) ?></dd></div>
      <div><dt>Cash received</dt><dd><?= pos_peso((int) $lastSale['tender']) ?></dd></div>
      <div><dt>Change</dt><dd class="pos-change-final"><?= pos_peso((int) $lastSale['change']) ?></dd></div>
      <div><dt>Customer</dt><dd><?= $lastSale['customer'] !== null ? e($lastSale['customer']) : 'Walk-in' ?></dd></div>
    </dl>
    <p><a class="btnlink" href="<?= e(vulcatrack_url('/admin/transaction-summary.php?id=' . (int) $lastSale['sale_id'])) ?>">View / print Transaction Summary</a></p>
    <p class="muted">Recorded <?= e($lastSale['sale_date']) ?>. The cart is ready for the next sale.</p>
  </section>
<?php endif; ?>

<?php foreach ($flashes as [$type, $message]): ?>
  <p class="<?= $type === 'error' ? 'error' : 'notice' ?>"><?= e($message) ?></p>
<?php endforeach; ?>
<?php foreach ($errors as $message): ?>
  <p class="error" role="alert"><?= e($message) ?></p>
<?php endforeach; ?>

<!-- ================= current sale ================= -->
<section class="card" id="sale">
  <h2>Current sale</h2>

  <div class="pos-customer">
    <p>
      Customer:
      <?php if ($linkedCustomer !== null): ?>
        <strong><?= e($linkedCustomer['full_name']) ?></strong>
        <span class="muted">(<?= e($linkedCustomer['email']) ?>)</span>
      <?php else: ?>
        <strong>Walk-in</strong>
      <?php endif; ?>
    </p>
    <?php if ($linkedCustomer !== null): ?>
      <form method="post" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>">
        <?= Csrf::field() ?><?= pos_filter_fields($filters) ?>
        <input type="hidden" name="_action" value="unlink_customer">
        <button type="submit" class="linklike">Make walk-in</button>
      </form>
    <?php endif; ?>
  </div>

  <details class="pos-link"<?= $customerQuery !== '' ? ' open' : '' ?>>
    <summary>Link a registered customer (optional)</summary>
    <form class="filterbar" method="get" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>">
      <?= pos_filter_fields($filters) ?>
      <label>Name, email or contact
        <input type="text" name="cq" value="<?= e($customerQuery) ?>" maxlength="100">
      </label>
      <button type="submit">Find</button>
    </form>
    <?php if ($customerQuery !== '' && mb_strlen($customerQuery) < 2): ?>
      <p class="muted">Type at least 2 characters.</p>
    <?php elseif ($customerQuery !== '' && !$customerResults): ?>
      <p class="muted">No registered customer matches. Leave the sale as walk-in — the POS does not create accounts.</p>
    <?php elseif ($customerResults): ?>
      <ul class="pos-customers">
        <?php foreach ($customerResults as $c): ?>
          <li>
            <span><strong><?= e($c['full_name']) ?></strong> <span class="muted"><?= e($c['email']) ?> · <?= e($c['contact_number']) ?></span></span>
            <form method="post" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>">
              <?= Csrf::field() ?><?= pos_filter_fields($filters) ?>
              <input type="hidden" name="_action" value="link_customer">
              <input type="hidden" name="customer_id" value="<?= (int) $c['customer_id'] ?>">
              <button type="submit" class="secondary">Link</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </details>

  <?php if (!$view['rows']): ?>
    <p class="muted">No items yet. Add products or services from the list below.</p>
  <?php else: ?>
    <form id="pos-sale" method="post" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>"
          data-total="<?= (int) $view['total_centavos'] ?>">
      <?= Csrf::field() ?><?= pos_filter_fields($filters) ?>
      <input type="hidden" name="expected_total" value="<?= (int) $view['total_centavos'] ?>">

      <div class="table-scroll">
      <table class="datatable pos-cart">
        <thead>
          <tr><th>Item</th><th class="num">Unit price</th><th>Qty</th><th class="num">Subtotal</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($view['rows'] as $row): ?>
          <tr>
            <td>
              <?= e($row['item_name']) ?>
              <?php if ($row['item_type'] !== null): ?>
                <span class="badge badge--<?= $row['item_type'] === 'product' ? 'product' : 'service' ?>"><?= $row['item_type'] === 'product' ? 'Product' : 'Service' ?></span>
              <?php endif; ?>
              <?php if ($row['problem'] !== null): ?><small class="error"><?= e($row['problem']) ?></small><?php endif; ?>
            </td>
            <td class="num"><?= pos_peso((int) $row['unit_centavos']) ?></td>
            <td>
              <input type="text" class="qty-input" name="qty[<?= (int) $row['item_id'] ?>]"
                     value="<?= (int) $row['quantity'] ?>" inputmode="numeric" maxlength="4"
                     aria-label="Quantity of <?= e($row['item_name']) ?>">
            </td>
            <td class="num"><?= pos_peso((int) $row['subtotal_centavos']) ?></td>
            <td>
              <button type="submit" class="linklike" form="pos-remove" name="item_id"
                      value="<?= (int) $row['item_id'] ?>">Remove</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="pos-totalrow"><th colspan="3">Total</th><td class="num"><?= pos_peso((int) $view['total_centavos']) ?></td><td></td></tr>
        </tfoot>
      </table>
      </div>

      <div class="pos-actions">
        <!-- first submit button: pressing Enter in a quantity box updates quantities -->
        <button type="submit" name="_action" value="update" class="secondary">Update quantities</button>
      </div>

      <div class="pos-pay">
        <div>
          <label for="cash_tendered">Cash received (&#8369;)</label>
          <input type="text" id="cash_tendered" name="cash_tendered" inputmode="decimal" maxlength="14"
                 value="<?= e($tenderInput) ?>" autocomplete="off"
                 onkeydown="if (event.key === 'Enter') { event.preventDefault(); }">
        </div>
        <p class="pos-change">Change: <output id="pos-change" for="cash_tendered">—</output></p>
      </div>
      <p class="muted">Change is shown for convenience; the server re-checks the cash against the total. Cash received is not stored.</p>

      <button type="submit" name="_action" value="checkout">Complete sale</button>
    </form>

    <form id="pos-remove" method="post" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>">
      <?= Csrf::field() ?><?= pos_filter_fields($filters) ?>
      <input type="hidden" name="_action" value="remove">
    </form>

    <form class="pos-cancel" method="post" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>">
      <?= Csrf::field() ?><?= pos_filter_fields($filters) ?>
      <input type="hidden" name="_action" value="clear">
      <button type="submit" class="linklike" onclick="return confirm('Cancel this sale and empty the cart?');">Cancel sale</button>
    </form>
  <?php endif; ?>
</section>

<!-- ================= add items ================= -->
<section id="items">
  <h2>Add items</h2>
  <form class="filterbar" method="get" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>">
    <label>Search
      <input type="text" name="q" value="<?= e($filters['q']) ?>" maxlength="150" placeholder="name or category">
    </label>
    <label>Type
      <select name="type">
        <option value=""<?= $filters['type'] === '' ? ' selected' : '' ?>>All</option>
        <option value="product"<?= $filters['type'] === 'product' ? ' selected' : '' ?>>Product</option>
        <option value="service"<?= $filters['type'] === 'service' ? ' selected' : '' ?>>Service</option>
      </select>
    </label>
    <button type="submit">Filter</button>
    <?php if ($filters['q'] !== '' || $filters['type'] !== ''): ?>
      <a href="<?= e(vulcatrack_url('/admin/pos.php')) ?>">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (!$catalog): ?>
    <p class="muted">No active items match.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table class="datatable pos-catalog">
      <thead>
        <tr><th>Item</th><th>Type</th><th class="num">Price</th><th class="num">Stock</th><th>Add</th></tr>
      </thead>
      <tbody>
      <?php foreach ($catalog as $row): ?>
        <?php $isProduct = $row['item_type'] === 'product'; $outOfStock = $isProduct && (int) $row['stock_quantity'] < 1; ?>
        <tr>
          <td>
            <?= e($row['item_name']) ?>
            <?php if ($row['category'] !== null && $row['category'] !== ''): ?><br><span class="muted"><?= e($row['category']) ?></span><?php endif; ?>
          </td>
          <td><span class="badge badge--<?= $isProduct ? 'product' : 'service' ?>"><?= $isProduct ? 'Product' : 'Service' ?></span></td>
          <td class="num"><?= pos_peso((int) $row['price_centavos']) ?></td>
          <td class="num"><?= $isProduct ? (int) $row['stock_quantity'] : '<span class="muted">n/a</span>' ?></td>
          <td>
            <?php if ($outOfStock): ?>
              <span class="badge badge--low">Out of stock</span>
            <?php else: ?>
              <form class="pos-addform" method="post" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>">
                <?= Csrf::field() ?><?= pos_filter_fields($filters) ?>
                <input type="hidden" name="_action" value="add">
                <input type="hidden" name="item_id" value="<?= (int) $row['item_id'] ?>">
                <input type="text" class="qty-input" name="quantity" value="1" inputmode="numeric" maxlength="4"
                       aria-label="Quantity of <?= e($row['item_name']) ?> to add">
                <button type="submit" class="secondary">Add</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>

<script>
/* Convenience only: live change display. Integer centavos, no float maths.
   The server re-validates the cash against the authoritative total. */
(function () {
  var form = document.getElementById('pos-sale');
  var input = document.getElementById('cash_tendered');
  var out = document.getElementById('pos-change');
  if (!form || !input || !out) { return; }
  var total = parseInt(form.getAttribute('data-total'), 10);
  function toCentavos(s) {
    var m = /^(\d{1,8})(?:\.(\d{1,2}))?$/.exec(s.trim());
    if (!m) { return null; }
    return parseInt(m[1], 10) * 100 + parseInt(((m[2] || '') + '00').slice(0, 2), 10);
  }
  function fmt(c) {
    var r = c % 100;
    return '₱' + Math.floor(c / 100) + '.' + (r < 10 ? '0' : '') + r;
  }
  function sync() {
    var c = toCentavos(input.value);
    if (input.value.trim() === '') { out.textContent = '—'; out.className = ''; return; }
    if (c === null) { out.textContent = 'enter an amount like 500 or 500.00'; out.className = 'error'; return; }
    if (c < total) { out.textContent = 'not enough (short by ' + fmt(total - c) + ')'; out.className = 'error'; return; }
    out.textContent = fmt(c - total); out.className = '';
  }
  input.addEventListener('input', sync);
  sync();
})();
</script>

<?php require __DIR__ . '/../src/Views/partials/admin_bottom.php'; ?>
