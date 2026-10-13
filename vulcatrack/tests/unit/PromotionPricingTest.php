<?php

namespace VulcaTrack\Tests;

use VulcaTrack\Support\PromotionLifecycle;
use VulcaTrack\Support\PromotionPricing;

function promotion_test_row(array $overrides = []): array
{
    return array_replace([
        'promotion_id' => 7, 'item_id' => 3, 'sale_price_centavos' => 155000,
        'starts_on' => '2026-10-13', 'ends_on' => '2026-10-15',
        'unit_limit' => 10, 'units_consumed' => 8, 'cancelled_at' => null,
    ], $overrides);
}

test('promotion lifecycle derives scheduled, inclusive active, exhausted, expired and cancelled states', function () {
    $p = promotion_test_row();
    assert_same('scheduled', PromotionLifecycle::status($p, '2026-10-12'));
    assert_same('active', PromotionLifecycle::status($p, '2026-10-13'));
    assert_same('active', PromotionLifecycle::status($p, '2026-10-15'));
    assert_same('ended', PromotionLifecycle::status($p, '2026-10-16'));
    assert_same('ended', PromotionLifecycle::status(promotion_test_row(['units_consumed' => 10]), '2026-10-14'));
    assert_same('cancelled', PromotionLifecycle::status(promotion_test_row(['cancelled_at' => '2026-10-14 12:00:00']), '2026-10-14'));
    assert_same('cancelled', PromotionLifecycle::status(promotion_test_row(['cancelled_at' => '2026-10-14 12:00:00']), '2026-10-20'));
    assert_null(PromotionLifecycle::remaining(promotion_test_row(['unit_limit' => null])));
    assert_same(2, PromotionLifecycle::remaining($p));
    assert_true(PromotionLifecycle::validDay('2026-10-13'));
    assert_false(PromotionLifecycle::validDay('2026-02-30'));
});

test('open-ended promotions stay active after their start until exhausted or cancelled', function () {
    $open = promotion_test_row(['starts_on' => '2026-10-20', 'ends_on' => null]);
    assert_same('scheduled', PromotionLifecycle::status($open, '2026-10-19'));
    assert_same('active', PromotionLifecycle::status($open, '2026-10-20'));
    assert_same('active', PromotionLifecycle::status($open, '2030-01-01'));
    assert_same('ended', PromotionLifecycle::status(array_replace($open, ['units_consumed' => 10]), '2030-01-01'));
    assert_same('cancelled', PromotionLifecycle::status(array_replace($open,
        ['cancelled_at' => '2026-10-21 09:00:00']), '2030-01-01'));
    $item = ['item_id' => 3, 'item_type' => 'product', 'price_centavos' => 180000];
    assert_same(7, PromotionPricing::lines($item, $open, 1, '2030-01-01', false)[0]['promotion_id']);
    assert_null(PromotionPricing::lines($item, $open, 1, '2030-01-01', true)[0]['promotion_id']);
});

test('promotion pricing splits one Product quantity without changing regular or Rescue pricing', function () {
    $item = ['item_id' => 3, 'item_type' => 'product', 'price_centavos' => 180000];
    $p = promotion_test_row();
    $lines = PromotionPricing::lines($item, $p, 5, '2026-10-14', false);
    assert_same([2, 3], array_column($lines, 'quantity'));
    assert_same([155000, 180000], array_column($lines, 'unit_centavos'));
    assert_same([310000, 540000], array_column($lines, 'subtotal_centavos'));
    assert_same([7, null], array_column($lines, 'promotion_id'));
    $rescue = PromotionPricing::lines($item, $p, 5, '2026-10-14', true);
    assert_same(1, count($rescue));
    assert_same(900000, $rescue[0]['subtotal_centavos']);
    assert_null($rescue[0]['promotion_id']);
    assert_same(900000, PromotionPricing::lines($item, $p, 5, '2026-10-16', false)[0]['subtotal_centavos']);
});

test('uncapped promotion prices all units; an exhausted cap falls back to regular', function () {
    $item = ['item_id' => 3, 'item_type' => 'product', 'price_centavos' => 180000];
    $uncapped = promotion_test_row(['unit_limit' => null, 'units_consumed' => 40]);
    $lines = PromotionPricing::lines($item, $uncapped, 5, '2026-10-14', false);
    assert_same(1, count($lines));
    assert_same(5, $lines[0]['quantity']);
    assert_same(7, $lines[0]['promotion_id']);
    $exhausted = promotion_test_row(['units_consumed' => 10]);
    assert_null(PromotionPricing::lines($item, $exhausted, 1, '2026-10-14', false)[0]['promotion_id']);
});
