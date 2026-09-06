<?php
/**
 * Unit tests for VulcaTrack\Support\Money — integer-centavo conversion and
 * formatting (Decision 55). No database, no floats.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Support\Money;

test('Money::toCentavos parses whole, two-decimal and zero amounts', function () {
    assert_same(10000, Money::toCentavos('100'));
    assert_same(10000, Money::toCentavos('100.00'));
    assert_same(9995, Money::toCentavos('99.95'));
    assert_same(5, Money::toCentavos('0.05'));
    assert_same(0, Money::toCentavos('0'));
    assert_same(0, Money::toCentavos('0.00'));
    assert_same(150, Money::toCentavos('1.5'));   // one decimal place is fine
    assert_same(2500, Money::toCentavos(25));      // whole-peso integer
});

test('Money::format renders integer centavos as a two-place decimal string', function () {
    assert_same('99.95', Money::format(9995));
    assert_same('100.00', Money::format(10000));
    assert_same('0.00', Money::format(0));
    assert_same('0.05', Money::format(5));
    assert_same('1.50', Money::format(150));
});

test('Money round-trips a DB-style decimal string without drift', function () {
    foreach (['0.00', '0.01', '7.00', '19.99', '1234.56', '99999999.99'] as $decimal) {
        assert_same($decimal, Money::format(Money::toCentavos($decimal)), "round-trip {$decimal}");
    }
});

test('Money::tryToCentavos rejects malformed input and never rounds excess precision', function () {
    foreach (['', '   ', 'abc', '10.', '.5', '1,000.00', '1e3', '10.5x', '-5.00', '- 5', '10.999', '5.001', '0.000', null, 3.14, []] as $bad) {
        assert_null(Money::tryToCentavos($bad), 'rejects ' . var_export($bad, true));
    }
});

test('Money::toCentavos throws on malformed input', function () {
    assert_throws(fn () => Money::toCentavos('12.345'), \InvalidArgumentException::class);
    assert_throws(fn () => Money::toCentavos('not money'), \InvalidArgumentException::class);
    assert_throws(fn () => Money::toCentavos(-1), \InvalidArgumentException::class);
});

test('Money rejects amounts DECIMAL(10,2) cannot hold', function () {
    assert_same(Money::MAX_CENTAVOS, Money::toCentavos('99999999.99'));
    assert_null(Money::tryToCentavos('100000000.00'));
});

test('Money arithmetic stays integer: a line subtotal is centavos * quantity', function () {
    $unit = Money::toCentavos('19.99');
    $subtotal = $unit * 3;                 // plain integer maths, no float
    assert_same(5997, $subtotal);
    assert_same('59.97', Money::format($subtotal));
    assert_true(is_int($subtotal));
});
