<?php
/**
 * Unit tests for VulcaTrack\Service\PosCart — the temporary session cart
 * behind the admin POS (Decision 57). Uses a plain array in place of
 * $_SESSION; no database (describe() is covered by the POS HTTP test).
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Service\PosCart;
use VulcaTrack\Service\PosCartException;

test('PosCart starts empty as a walk-in sale and writes its state into the session array', function () {
    $session = [];
    $cart = new PosCart($session);
    assert_true($cart->isEmpty());
    assert_null($cart->customerId());
    assert_same([], $cart->toSaleLines());

    $cart->add(7, 2);
    assert_same([7 => 2], $session['pos_cart']['lines'], 'state lives in the session by reference');

    $again = new PosCart($session);
    assert_same([7 => 2], $again->lines(), 'a later request sees the same cart');
});

test('PosCart::add merges an item already in the cart into one line (deterministic)', function () {
    $session = [];
    $cart = new PosCart($session);
    $cart->add(5, 1);
    $cart->add(9, 3);
    assert_same(4, $cart->add(5, 3), 'returns the new line quantity');
    assert_same([5 => 4, 9 => 3], $cart->lines(), 'one line per item, original add order kept');
    assert_same(
        [['item_id' => 5, 'quantity' => 4], ['item_id' => 9, 'quantity' => 3]],
        $cart->toSaleLines(),
        'SaleService line shape'
    );
});

test('PosCart rejects bad item ids and quantities without changing the cart', function () {
    $session = [];
    $cart = new PosCart($session);
    $cart->add(1, 1);
    foreach ([0, -1, PosCart::MAX_QUANTITY + 1] as $bad) {
        assert_throws(fn () => $cart->add(2, $bad), PosCartException::class, 'Quantity');
    }
    assert_throws(fn () => $cart->add(0, 1), PosCartException::class);
    assert_throws(fn () => $cart->setQuantity(1, 0), PosCartException::class);
    assert_throws(fn () => $cart->setQuantity(99, 1), PosCartException::class, 'no longer in the sale');
    assert_same([1 => 1], $cart->lines());
});

test('PosCart enforces MAX_QUANTITY per item across repeated adds', function () {
    $session = [];
    $cart = new PosCart($session);
    $cart->add(3, PosCart::MAX_QUANTITY - 1);
    $cart->add(3, 1);
    assert_same(PosCart::MAX_QUANTITY, $cart->quantityOf(3));
    assert_throws(fn () => $cart->add(3, 1), PosCartException::class, (string) PosCart::MAX_QUANTITY);
    assert_same(PosCart::MAX_QUANTITY, $cart->quantityOf(3), 'unchanged after the refusal');
});

test('PosCart enforces MAX_LINES distinct items but still allows topping up an existing line', function () {
    $session = [];
    $cart = new PosCart($session);
    for ($i = 1; $i <= PosCart::MAX_LINES; $i++) {
        $cart->add($i, 1);
    }
    assert_throws(fn () => $cart->add(PosCart::MAX_LINES + 1, 1), PosCartException::class, 'at most');
    assert_same(2, $cart->add(1, 1), 'an existing line can still grow');
    assert_count(PosCart::MAX_LINES, $cart->lines());
});

test('PosCart setQuantity / remove / customer link / clear', function () {
    $session = [];
    $cart = new PosCart($session);
    $cart->add(4, 1);
    $cart->add(8, 1);
    $cart->setQuantity(4, 6);
    $cart->remove(8);
    $cart->remove(12345); // removing an absent item is a harmless no-op
    assert_same([4 => 6], $cart->lines());

    $cart->setCustomer(42);
    assert_same(42, $cart->customerId());
    assert_throws(fn () => $cart->setCustomer(0), PosCartException::class);
    $cart->setCustomer(null);
    assert_null($cart->customerId(), 'null = walk-in');

    $cart->setCustomer(42);
    $cart->clear();
    assert_true($cart->isEmpty());
    assert_null($cart->customerId(), 'clearing the sale also returns to walk-in');
});

test('PosCart drops malformed session data instead of trusting it', function () {
    $session = ['pos_cart' => [
        'lines' => [3 => 2, 'x' => 1, -4 => 1, 5 => 0, 6 => '2', 7 => PosCart::MAX_QUANTITY + 1, 8 => 1],
        'customer_id' => '9',
    ]];
    $cart = new PosCart($session);
    assert_same([3 => 2, 8 => 1], $cart->lines());
    assert_null($cart->customerId());

    $session = ['pos_cart' => 'garbage'];
    assert_true((new PosCart($session))->isEmpty());
});
