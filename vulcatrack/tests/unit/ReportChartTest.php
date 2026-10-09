<?php
/**
 * Unit tests for VulcaTrack\Support\ReportChart — the Sales Reports chart
 * window, zero-day filling, y-axis scale, x-label spacing and revenue shares
 * (Phase 7.4d). No database.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Support\ReportChart;
use VulcaTrack\Support\ReportPeriod;

test('ReportPeriod validates query state and uses exact calendar boundaries', function () {
    $today = '2026-10-09';
    assert_same(['from' => '2026-10-03', 'to' => $today, 'days' => 7], array_intersect_key(ReportPeriod::resolve(null, null, $today), array_flip(['from', 'to', 'days'])));
    assert_same('2026-09-10', ReportPeriod::resolve('30d', null, $today)['from']);
    assert_same('2025-10-10', ReportPeriod::resolve('365d', null, $today)['from']);
    $february = ReportPeriod::resolve('month', '2024-02', $today);
    assert_same('2024-02-29', $february['to'], 'leap-year month ends on the 29th');
    assert_same(29, $february['days']);
    assert_same('Feb 2024', $february['label']);
    foreach (['2026-13', '2026-2', '2026-02-01', ['2026-02'], '0999-12'] as $badMonth) {
        assert_same('7d', ReportPeriod::resolve('month', $badMonth, $today)['period']);
    }
    assert_same('30d', ReportPeriod::resolve(['365d'], null, $today, '30d')['period']);
    assert_same('7d', ReportPeriod::resolve('today', null, $today)['period'], 'Today is reserved for the Reports card link');
    assert_same(1, ReportPeriod::resolve('today', null, $today, '30d', true)['days']);
    $options = ReportPeriod::monthOptions(['2001-07', '2026-08'], $today, null);
    assert_same('2026-10', $options[0]);
    assert_same('2001-07', end($options));
    assert_true(in_array('2001-08', $options, true), 'empty months between recorded sales remain selectable');
    assert_count(12, ReportPeriod::monthOptions([], $today, null), 'an empty shop can still choose the past year of months');
});

test('ReportChart fills rolling-year monthly buckets including partial and empty months', function () {
    $rows = [['month' => '2026-01', 'transaction_count' => 2, 'total_centavos' => 12345]];
    $filled = ReportChart::fillMonths($rows, '2025-12-15', '2026-02-03');
    assert_same(['2025-12', '2026-01', '2026-02'], array_column($filled, 'month'));
    assert_same([0, 2, 0], array_column($filled, 'transaction_count'));
    assert_same([0, 12345, 0], array_column($filled, 'total_centavos'));
});

test('ReportChart::window charts a From–To range of up to 92 days in full', function () {
    assert_same(['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30, 'follows_filter' => true],
        ReportChart::window('2026-09-01', '2026-09-30', '2026-09-29'));
    assert_same(['from' => '2026-09-29', 'to' => '2026-09-29', 'days' => 1, 'follows_filter' => true],
        ReportChart::window('2026-09-29', '2026-09-29', '2026-09-29'), 'one exact day');
    assert_same(['from' => '2026-07-01', 'to' => '2026-09-30', 'days' => 92, 'follows_filter' => true],
        ReportChart::window('2026-07-01', '2026-09-30', '2001-01-01'), 'exactly 92 days is still the full range');
    assert_same(['from' => '2024-02-01', 'to' => '2024-03-01', 'days' => 30, 'follows_filter' => true],
        ReportChart::window('2024-02-01', '2024-03-01', '2026-09-29'), 'leap-year February counted by the calendar');
});

test('ReportChart::window falls back to the 30 days ending at To, or today', function () {
    $last30 = fn (string $from, string $to) => ['from' => $from, 'to' => $to, 'days' => 30, 'follows_filter' => false];

    assert_same($last30('2026-08-31', '2026-09-29'), ReportChart::window(null, null, '2026-09-29'), 'no filter → the 30 days up to today');
    assert_same($last30('2026-08-31', '2026-09-29'), ReportChart::window('2026-09-20', null, '2026-09-29'), 'From only → still up to today');
    assert_same($last30('2001-06-11', '2001-07-10'), ReportChart::window(null, '2001-07-10', '2026-09-29'), 'To only → ends at To');
    assert_same($last30('2026-09-01', '2026-09-30'), ReportChart::window('2026-06-30', '2026-09-30', '2026-09-29'), '93 days → the 30 ending at To');
    assert_same($last30('9999-12-02', '9999-12-31'), ReportChart::window(null, '9999-12-31', '2026-09-29'), 'the last DATETIME day');
    assert_same(['from' => '1000-01-01', 'to' => '1000-01-05', 'days' => 5, 'follows_filter' => false],
        ReportChart::window(null, '1000-01-05', '2026-09-29'), 'never starts before the first DATETIME day');
});

test('ReportChart::fillDays keeps recorded days and fills every other day with a real zero', function () {
    $rows = [ // listDailyTotals() order: newest first, only days with sales
        ['day' => '2026-09-03', 'transaction_count' => 1, 'total_centavos' => 4550],
        ['day' => '2026-09-01', 'transaction_count' => 2, 'total_centavos' => 58650],
        ['day' => '2026-08-15', 'transaction_count' => 9, 'total_centavos' => 99900], // outside the window: ignored
    ];
    assert_same([
        ['day' => '2026-09-01', 'transaction_count' => 2, 'total_centavos' => 58650],
        ['day' => '2026-09-02', 'transaction_count' => 0, 'total_centavos' => 0],
        ['day' => '2026-09-03', 'transaction_count' => 1, 'total_centavos' => 4550],
        ['day' => '2026-09-04', 'transaction_count' => 0, 'total_centavos' => 0],
    ], ReportChart::fillDays($rows, '2026-09-01', 4));

    $days = ReportChart::fillDays([], '2026-02-27', 4);
    assert_same(['2026-02-27', '2026-02-28', '2026-03-01', '2026-03-02'], array_column($days, 'day'), 'month boundary');
    assert_same(0, array_sum(array_column($days, 'total_centavos')), 'no sales → all zero, nothing invented');
    assert_same(['9999-12-30', '9999-12-31'], array_column(ReportChart::fillDays([], '9999-12-30', 2), 'day'), 'ends on the last day');
});

test('ReportChart::scale picks a clean whole-peso step and an axis top at or above the largest day', function () {
    assert_same(['step' => 0, 'max' => 0], ReportChart::scale(0), 'no sales → no scale');
    assert_same(['step' => 1000000, 'max' => 4000000], ReportChart::scale(3124800), '₱31,248 → ₱10,000 steps to ₱40,000');
    assert_same(['step' => 20000, 'max' => 60000], ReportChart::scale(58650), '₱586.50 → ₱200 steps to ₱600');
    assert_same(['step' => 250000, 'max' => 1000000], ReportChart::scale(1000000), 'an exact top is not padded: ₱2,500 steps to ₱10,000');
    assert_same(['step' => 100, 'max' => 100], ReportChart::scale(50), 'under ₱1 → a ₱1 step (no fractional ticks)');
    foreach ([1, 99, 4550, 19550, 300000, 999999, 123456789] as $c) {
        $s = ReportChart::scale($c);
        assert_true($s['max'] >= $c && $s['max'] - $s['step'] < $c, "top covers {$c} with no spare step");
        assert_same(0, $s['step'] % 100, "whole-peso step for {$c}");
        assert_true($s['max'] / $s['step'] <= 5, "at most 5 gridlines for {$c}");
    }
});

test('ReportChart::labelIndexes labels short windows fully and long ones sparsely, always first and last', function () {
    assert_same([], ReportChart::labelIndexes(0));
    assert_same([0], ReportChart::labelIndexes(1));
    assert_same([0, 1, 2, 3, 4, 5], ReportChart::labelIndexes(6));
    assert_same([0, 6, 12, 18, 24, 29], ReportChart::labelIndexes(30));
    assert_same([0, 19, 38, 57, 76, 91], ReportChart::labelIndexes(92));
    foreach ([7, 8, 13, 31, 45, 60, 91] as $n) {
        $idx = ReportChart::labelIndexes($n);
        assert_same(0, $idx[0], "{$n}: first day labelled");
        assert_same($n - 1, end($idx), "{$n}: last day labelled");
        assert_true(count($idx) <= 6, "{$n}: at most 6 labels");
        for ($i = 1; $i < count($idx); $i++) {
            assert_true($idx[$i] - $idx[$i - 1] >= 2, "{$n}: labels never on neighbouring days");
        }
    }
});

test('ReportChart::shares gives one-decimal revenue shares that add up to 100, or null when there is nothing to share', function () {
    assert_same(['63.2', '36.8'], ReportChart::shares(632, 368));
    assert_same(['100.0', '0.0'], ReportChart::shares(378200, 0), 'In-shop only');
    assert_same(['0.0', '100.0'], ReportChart::shares(0, 50000), 'Rescue only');
    assert_same(['33.3', '66.7'], ReportChart::shares(1, 2));
    assert_same(['12.5', '87.5'], ReportChart::shares(125, 875), 'exact halves stay exact');
    assert_same(['0.1', '99.9'], ReportChart::shares(1, 999), 'a tiny share rounds half up');
    assert_same(['0.0', '100.0'], ReportChart::shares(1, 99999), 'below 0.05% rounds to 0.0');
    assert_null(ReportChart::shares(0, 0), 'no revenue → no percentages at all');
    assert_null(ReportChart::shares(-5, 10), 'never a share of a negative amount');
});
