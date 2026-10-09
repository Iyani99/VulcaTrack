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
 *
 * Layout (Phase 7.3c): a catalogue of item cards on the left and the Current
 * sale panel on the right (stacked on narrow screens). Presentation only —
 * the forms, field names, form owners and Enter-key behaviour are unchanged.
 *
 * Rescue sales (Phase 7.3d): the Rescue detail page POSTs `start_rescue`,
 * which puts the session cart into Rescue mode for that request (only from a
 * clean cart) and locks the customer to the request's customer. Checkout then
 * passes the request id FROM THE SESSION to SaleService, which re-validates
 * everything and links the sale. Because every tab shares the one session
 * cart, every cart-changing form carries `expected_rescue_id` (the context it
 * was rendered for: "" = ordinary sale) and is refused if the cart's context
 * has changed since.
 */

use VulcaTrack\Auth\Csrf;
use VulcaTrack\Repository\CustomerRepository;
use VulcaTrack\Repository\ItemRepository;
use VulcaTrack\Repository\SaleRepository;
use VulcaTrack\Repository\ServiceRequestRepository;
use VulcaTrack\Service\PosCart;
use VulcaTrack\Service\PosCartException;
use VulcaTrack\Service\SaleException;
use VulcaTrack\Service\SaleService;
use VulcaTrack\Support\Money;
use VulcaTrack\Support\OtgStatus;

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/auth.php';

$admin     = require_admin();
$pdo       = vulcatrack_db();
$items     = new ItemRepository($pdo);
$customers = new CustomerRepository($pdo);
$sales     = new SaleRepository($pdo);
$requests  = new ServiceRequestRepository($pdo);
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

/** The context this page was rendered for, carried by every cart-changing form (stale-tab guard). */
function pos_context_field(PosCart $cart): string
{
    return '<input type="hidden" name="expected_rescue_id" value="' . e($cart->contextToken()) . '">';
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
    return '&#8369;' . e(Money::formatDisplay($centavos));
}

/**
 * Product stock chip [modifier, label] — the same chips as Inventory
 * (Phase 7.3b), from the same low-stock rule: $isLow comes from
 * ItemRepository::lowStockProducts(). Presentation only; the catalogue lists
 * active items only, and the stock check that matters stays in SaleService.
 */
function pos_stock_chip(array $row, bool $isLow): array
{
    $stock = (int) $row['stock_quantity'];
    if ($stock < 1) {
        return [$isLow ? 'low' : 'out', 'Out: ' . $stock];
    }
    return $isLow ? ['low', 'Low: ' . $stock] : ['ok', 'OK: ' . $stock];
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

    // --- start_rescue (posted by the Rescue detail page) -------------------
    // The request is re-checked here (the page's button is only a convenience);
    // SaleService checks it again, under a lock, at checkout.
    if ($action === 'start_rescue') {
        $requestId = pos_digits($_POST['request_id'] ?? null, 10) ?? 0;
        $rescue    = ($requestId > 0 && $requestId <= 2147483647) ? $requests->findForAdmin($requestId) : null;
        try {
            if ($rescue === null) {
                throw new PosCartException('That Rescue request was not found.');
            }
            if (!OtgStatus::canRecordSale((string) $rescue['status'])) {
                throw new PosCartException(
                    "A sale can only be recorded for an accepted or completed Rescue request; request #{$requestId} is {$rescue['status']}."
                );
            }
            $linked = $sales->findForServiceRequest($requestId);
            if ($linked !== null) {
                throw new PosCartException("Rescue request #{$requestId} already has a recorded sale (Sale #{$linked['sale_id']}).");
            }
            if ($customers->findById((int) $rescue['customer_id']) === null) {
                throw new PosCartException("The customer of Rescue request #{$requestId} could not be found.");
            }
            $started = $cart->startRescue($requestId, (int) $rescue['customer_id']);
            pos_flash('notice', $started
                ? "Recording a sale for Rescue request #{$requestId} ({$rescue['customer_name']}). Add the items and services actually used."
                : "Rescue request #{$requestId} is already open in the POS.");
        } catch (PosCartException $e) {
            pos_flash('error', $e->getMessage());
        }
        header('Location: ' . pos_url(['q' => '', 'type' => '']));
        exit;
    }

    // --- stale-tab guard: the form must have been rendered for the cart's current context
    if (!$cart->matchesContext($_POST['expected_rescue_id'] ?? null)) {
        pos_flash('error', 'This sale changed in another window (a Rescue sale was started or ended). '
            . 'Nothing was changed — review the sale below and try again.');
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
                    $_SESSION['pos_cart_added'] = true;
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
                    $wasRescue = $cart->serviceRequestId();
                    $cart->clear();
                    pos_flash('notice', $wasRescue !== null
                        ? "Rescue sale for request #{$wasRescue} cancelled. Nothing was recorded; the POS is back to a walk-in sale."
                        : 'Sale cancelled. The cart is empty.');
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
            $errors[] = 'Cash received (₱' . Money::formatDisplay($tender) . ') is less than the total (₱'
                      . Money::formatDisplay($expected) . ').';
        }
    }

    if ($errors === []) {
        try {
            $rescueId = $cart->serviceRequestId();                  // from the session, never the form
            $result = (new SaleService($pdo))->checkout([
                'admin_id'                => (int) $admin['id'],   // from the session, never the form
                'customer_id'             => $cart->customerId(),
                'lines'                   => $cart->toSaleLines(),
                'expected_total_centavos' => $expected,
                'service_request_id'      => $rescueId,
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
                'rescue_id'      => $rescueId,
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
$animateCartAdd = !empty($_SESSION['pos_cart_added']);
unset($_SESSION['pos_cart_added']);
$lastSale = is_array($_SESSION['pos_last_sale'] ?? null) ? $_SESSION['pos_last_sale'] : null;
unset($_SESSION['pos_last_sale']);

// Rescue mode: re-read the request on every page load. If it can no longer
// take a sale, say so and hide Complete sale (SaleService would refuse anyway);
// the cart is kept and Cancel sale leaves Rescue mode.
$rescueId      = $cart->serviceRequestId();
$rescue        = $rescueId !== null ? $requests->findForAdmin($rescueId) : null;
$rescueProblem = null;
if ($rescueId !== null) {
    if ($rescue === null) {
        $rescueProblem = "Rescue request #{$rescueId} no longer exists.";
    } elseif (!OtgStatus::canRecordSale((string) $rescue['status'])) {
        $rescueProblem = "Rescue request #{$rescueId} is now {$rescue['status']}, so a sale can no longer be recorded for it.";
    } elseif (($recorded = $sales->findForServiceRequest($rescueId)) !== null) {
        $rescueProblem = "Rescue request #{$rescueId} already has a recorded sale (Sale #{$recorded['sale_id']}).";
    } elseif ((int) $rescue['customer_id'] !== $cart->customerId()) {
        $rescueProblem = "This sale's customer is not the customer of Rescue request #{$rescueId}.";
    }
}

$linkedCustomer = null;
if ($cart->customerId() !== null) {
    $linkedCustomer = $customers->findById($cart->customerId());
    if ($linkedCustomer === null && $rescueId === null) {
        $cart->setCustomer(null);
        $errors[] = 'The linked customer no longer exists; the sale is now a walk-in sale.';
    } elseif ($linkedCustomer === null) {
        $rescueProblem = $rescueProblem ?? "The customer of Rescue request #{$rescueId} could not be found.";
    }
}

$view = $cart->describe($items);

$customerQuery   = mb_substr(trim((string) ($_GET['cq'] ?? '')), 0, 100);
$customerResults = mb_strlen($customerQuery) >= 2 ? $customers->search($customerQuery, 10) : [];

$catalogFilters = ['active' => true];
if ($filters['q'] !== '')    { $catalogFilters['search'] = $filters['q']; }
if ($filters['type'] !== '') { $catalogFilters['type'] = $filters['type']; }
$catalog = $items->list($catalogFilters);
$catalogGroups = ['service' => [], 'product' => []];
foreach ($catalog as $row) {
    $catalogGroups[$row['item_type']][] = $row;
}

// Which catalogue products carry a low-stock alert — the exact Inventory rule.
$lowStockIds = [];
foreach ($items->lowStockProducts() as $p) {
    $lowStockIds[(int) $p['item_id']] = true;
}

$pageTitle = 'POS';
$navActive = 'pos';
require __DIR__ . '/../src/Views/partials/admin_top.php';
?>
<div class="pagehead">
  <div>
    <h1>Point of Sale</h1>
    <p class="pagehead__meta">Add items from the catalogue, then complete the sale in the Current sale panel.</p>
  </div>
</div>

<?php if ($lastSale !== null): ?>
  <section class="card card--sold" aria-live="polite">
    <h2>Sale #<?= (int) $lastSale['sale_id'] ?> recorded</h2>
    <dl class="pos-summary">
      <div><dt>Total</dt><dd><?= pos_peso((int) $lastSale['total_centavos']) ?></dd></div>
      <div><dt>Cash received</dt><dd><?= pos_peso((int) $lastSale['tender']) ?></dd></div>
      <div><dt>Change</dt><dd class="pos-change-final"><?= pos_peso((int) $lastSale['change']) ?></dd></div>
      <div><dt>Customer</dt><dd><?= $lastSale['customer'] !== null ? e($lastSale['customer']) : 'Walk-in' ?></dd></div>
      <?php if (!empty($lastSale['rescue_id'])): ?>
        <div><dt>Rescue</dt><dd>#<?= (int) $lastSale['rescue_id'] ?></dd></div>
      <?php endif; ?>
    </dl>
    <p class="pos-sold-links">
      <a class="btnlink" href="<?= e(vulcatrack_url('/admin/transaction-summary.php?id=' . (int) $lastSale['sale_id'])) ?>">View / print Transaction Summary</a>
      <?php if (!empty($lastSale['rescue_id'])): ?>
        <a class="btnlink btnlink--outline" href="<?= e(vulcatrack_url('/admin/rescue-view.php?id=' . (int) $lastSale['rescue_id'])) ?>">Back to Rescue #<?= (int) $lastSale['rescue_id'] ?></a>
      <?php endif; ?>
    </p>
    <p class="muted">Recorded <?= e($lastSale['sale_date']) ?>. The cart is ready for the next sale.</p>
  </section>
<?php endif; ?>

<?php foreach ($flashes as [$type, $message]): ?>
  <p class="<?= $type === 'error' ? 'error' : 'notice' ?>"><?= e($message) ?></p>
<?php endforeach; ?>
<?php foreach ($errors as $message): ?>
  <p class="error" role="alert"><?= e($message) ?></p>
<?php endforeach; ?>

<?php /* Phase 7.3c layout: catalogue (left) | current sale (right) on desktop,
   stacked catalogue-then-sale below the breakpoint. Every form below is the
   same as before — same fields, same form ids / owners, none nested; only
   the presentation around them changed. */ ?>
<?php if ($rescueId !== null): /* Phase 7.3d: this cart is recording a Rescue sale */ ?>
  <section class="pos-rescue<?= $rescueProblem !== null ? ' pos-rescue--problem' : '' ?>" aria-labelledby="pos-rescue-title">
    <div class="pos-rescue__main">
      <p class="pos-rescue__label">Rescue sale</p>
      <h2 class="pos-rescue__title" id="pos-rescue-title">Request #<?= (int) $rescueId ?></h2>
      <?php if ($rescue !== null): ?>
        <dl class="pos-rescue__meta">
          <div><dt>Customer</dt><dd><?= e($rescue['customer_name']) ?></dd></div>
          <div><dt>Status</dt><dd><?= e(OtgStatus::adminLabel((string) $rescue['status'])) ?></dd></div>
        </dl>
      <?php endif; ?>
    </div>
    <?php if ($rescue !== null): ?>
      <a class="pos-rescue__link" href="<?= e(vulcatrack_url('/admin/rescue-view.php?id=' . (int) $rescueId)) ?>">View request #<?= (int) $rescueId ?></a>
    <?php endif; ?>
    <?php if ($rescueProblem !== null): ?>
      <p class="pos-rescue__problem" role="alert"><?= e($rescueProblem) ?> The sale cannot be completed — use <strong>Cancel sale</strong> to leave Rescue mode.</p>
    <?php endif; ?>
  </section>
<?php endif; ?>
<div class="pos-layout">

<!-- ================= catalogue ================= -->
<section class="pos-catalog" id="items" aria-labelledby="pos-catalog-title">
  <h2 class="pos-pane-title" id="pos-catalog-title">Catalogue</h2>

  <div class="pos-tools">
    <form class="pos-search" method="get" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>" role="search">
      <?php if ($filters['type'] !== ''): ?><input type="hidden" name="type" value="<?= e($filters['type']) ?>"><?php endif; ?>
      <label class="sr-only" for="pos-q">Search items</label>
      <input type="text" id="pos-q" name="q" value="<?= e($filters['q']) ?>" maxlength="150" placeholder="Search by name or category">
      <button type="submit">Search</button>
    </form>
    <?php // Product/Service keep the search; All returns the full catalogue. ?>
    <nav class="chips" aria-label="Item type">
      <?php foreach (['' => 'All', 'product' => 'Products', 'service' => 'Services'] as $typeValue => $typeLabel): ?>
        <a class="chip<?= $filters['type'] === $typeValue ? ' is-active' : '' ?>" href="<?= e(pos_url(['q' => $typeValue === '' ? '' : $filters['q'], 'type' => $typeValue])) ?>"<?= $filters['type'] === $typeValue ? ' aria-current="true"' : '' ?>><?= e($typeLabel) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>

  <?php if ($view['rows']): /* stacked (narrow) layout only: the sale sits below the catalogue */ ?>
    <?php $lineCount = count($view['rows']); ?>
    <a class="pos-jump" href="#sale">Current sale: <?= $lineCount ?> <?= $lineCount === 1 ? 'item' : 'items' ?> &middot; <?= pos_peso((int) $view['total_centavos']) ?></a>
  <?php endif; ?>

  <?php if (!$catalog): ?>
    <p class="muted">No active items match.</p>
  <?php else: ?>
    <?php foreach (['service' => 'Services Offered', 'product' => 'Products'] as $groupType => $groupTitle): ?>
      <?php if (!$catalogGroups[$groupType]) { continue; } ?>
    <section class="pos-catalog-group" aria-labelledby="pos-group-<?= $groupType ?>">
      <h3 class="pos-catalog-group__title" id="pos-group-<?= $groupType ?>"><?= $groupTitle ?></h3>
      <ul class="pos-grid">
    <?php foreach ($catalogGroups[$groupType] as $row): ?>
      <?php
        $isProduct  = $row['item_type'] === 'product';
        $outOfStock = $isProduct && (int) $row['stock_quantity'] < 1;
      ?>
      <li class="pos-item<?= $outOfStock ? ' pos-item--out' : '' ?>">
        <div class="pos-item__tags">
          <span class="badge badge--<?= $isProduct ? 'product' : 'service' ?>"><?= $isProduct ? 'Product' : 'Service' ?></span>
          <?php if ($isProduct): ?>
          <?php [$chip, $chipLabel] = pos_stock_chip($row, isset($lowStockIds[(int) $row['item_id']])); ?>
          <span class="stock stock--<?= $chip ?>"><?= e($chipLabel) ?></span>
          <?php endif; ?>
        </div>
        <p class="pos-item__name"><?= e($row['item_name']) ?></p>
        <?php if ($row['category'] !== null && $row['category'] !== ''): ?>
          <p class="pos-item__cat"><?= e($row['category']) ?></p>
        <?php endif; ?>
        <p class="pos-item__price"><?= pos_peso((int) $row['price_centavos']) ?></p>
        <?php if ($outOfStock): ?>
          <p class="pos-item__unavailable"><span class="badge badge--low">Out of stock</span></p>
        <?php else: ?>
          <form class="pos-addform" method="post" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>">
            <?= Csrf::field() ?><?= pos_context_field($cart) ?><?= pos_filter_fields($filters) ?>
            <input type="hidden" name="_action" value="add">
            <input type="hidden" name="item_id" value="<?= (int) $row['item_id'] ?>">
            <input type="text" class="qty-input" name="quantity" value="1" inputmode="numeric" maxlength="4"
                   aria-label="Quantity of <?= e($row['item_name']) ?> to add">
            <button type="submit" class="secondary">Add</button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
      </ul>
    </section>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<!-- ================= current sale ================= -->
<section class="pos-sale<?= $animateCartAdd ? ' pos-sale--added' : '' ?>" id="sale" aria-labelledby="pos-sale-title">
  <h2 class="pos-sale__head" id="pos-sale-title">Current sale</h2>
  <div class="pos-sale__body">

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
    <?php if ($rescueId !== null): /* customer locked to the Rescue's (the server refuses changes too) */ ?>
      <p class="pos-customer__lock">Locked to Rescue request #<?= (int) $rescueId ?></p>
    <?php elseif ($linkedCustomer !== null): ?>
      <form method="post" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>">
        <?= Csrf::field() ?><?= pos_context_field($cart) ?><?= pos_filter_fields($filters) ?>
        <input type="hidden" name="_action" value="unlink_customer">
        <button type="submit" class="linklike">Make walk-in</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($rescueId === null): ?>
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
              <?= Csrf::field() ?><?= pos_context_field($cart) ?><?= pos_filter_fields($filters) ?>
              <input type="hidden" name="_action" value="link_customer">
              <input type="hidden" name="customer_id" value="<?= (int) $c['customer_id'] ?>">
              <button type="submit" class="secondary">Link</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </details>
  <?php endif; ?>

  <?php if (!$view['rows']): ?>
    <p class="muted pos-empty">No items yet. Add products or services from the catalogue.</p>
  <?php else: ?>
    <form id="pos-sale" method="post" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>"
          data-total="<?= (int) $view['total_centavos'] ?>">
      <?= Csrf::field() ?><?= pos_context_field($cart) ?><?= pos_filter_fields($filters) ?>
      <input type="hidden" name="expected_total" value="<?= (int) $view['total_centavos'] ?>">

      <div class="table-scroll">
      <table class="datatable pos-cart">
        <thead>
          <tr><th>Item</th><th>Qty</th><th class="num">Subtotal</th></tr>
        </thead>
        <tbody>
        <?php foreach ($view['rows'] as $row): ?>
          <tr>
            <td>
              <span class="pos-cart__name"><?= e($row['item_name']) ?></span>
              <span class="pos-cart__meta">
                <?php if ($row['item_type'] !== null): ?>
                  <span class="badge badge--<?= $row['item_type'] === 'product' ? 'product' : 'service' ?>"><?= $row['item_type'] === 'product' ? 'Product' : 'Service' ?></span>
                <?php endif; ?>
                <span><?= pos_peso((int) $row['unit_centavos']) ?> each</span>
              </span>
              <?php if ($row['problem'] !== null): ?><small class="error"><?= e($row['problem']) ?></small><?php endif; ?>
              <?php // owned by #pos-remove (form attribute), so it is never this form's Enter-key button ?>
              <button type="submit" class="linklike pos-cart__remove" form="pos-remove" name="item_id"
                      value="<?= (int) $row['item_id'] ?>">Remove</button>
            </td>
            <td>
              <input type="text" class="qty-input" name="qty[<?= (int) $row['item_id'] ?>]"
                     value="<?= (int) $row['quantity'] ?>" inputmode="numeric" maxlength="4"
                     aria-label="Quantity of <?= e($row['item_name']) ?>">
            </td>
            <td class="num"><?= pos_peso((int) $row['subtotal_centavos']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <div class="pos-actions">
        <!-- first submit button: pressing Enter in a quantity box updates quantities -->
        <button type="submit" name="_action" value="update" class="secondary">Update quantities</button>
      </div>

      <?php // the server-computed total (the same figure sent as expected_total) ?>
      <p class="pos-total">
        <span class="pos-total__label">Total due</span>
        <span class="pos-total__value"><?= pos_peso((int) $view['total_centavos']) ?></span>
      </p>

      <div class="pos-pay">
        <div class="pos-pay__cash">
          <label for="cash_tendered">Cash received (&#8369;)</label>
          <input type="text" id="cash_tendered" name="cash_tendered" inputmode="decimal" maxlength="14"
                 value="<?= e($tenderInput) ?>" autocomplete="off"
                 onkeydown="if (event.key === 'Enter') { event.preventDefault(); }">
        </div>
        <p class="pos-change">Change: <output id="pos-change" for="cash_tendered">—</output></p>
      </div>
      <p class="muted pos-note">Change is shown for convenience; the server re-checks the cash against the total. Cash received is not stored.</p>

      <?php if ($rescueProblem === null): ?>
        <button type="submit" name="_action" value="checkout" class="btnlink pos-complete">Complete sale</button>
      <?php else: ?>
        <p class="error pos-blocked">This Rescue sale cannot be completed (see the notice above).</p>
      <?php endif; ?>
    </form>

    <form id="pos-remove" method="post" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>">
      <?= Csrf::field() ?><?= pos_context_field($cart) ?><?= pos_filter_fields($filters) ?>
      <input type="hidden" name="_action" value="remove">
    </form>
  <?php endif; ?>

  <?php if ($view['rows'] || $rescueId !== null): /* in Rescue mode Cancel is also the way out of an empty Rescue sale */ ?>
    <form class="pos-cancel" method="post" action="<?= e(vulcatrack_url('/admin/pos.php')) ?>">
      <?= Csrf::field() ?><?= pos_context_field($cart) ?><?= pos_filter_fields($filters) ?>
      <input type="hidden" name="_action" value="clear">
      <button type="submit" class="linklike" onclick="return confirm('<?= $rescueId !== null ? 'Cancel this Rescue sale? Nothing is recorded and the POS returns to a walk-in sale.' : 'Cancel this sale and empty the cart?' ?>');">Cancel sale</button>
    </form>
  <?php endif; ?>
  </div>
</section>

</div><!-- /.pos-layout -->

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
    var whole = String(Math.floor(c / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return '₱' + whole + (r === 0 ? '' : '.' + (r < 10 ? '0' : '') + r);
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
