<?php

namespace VulcaTrack\Tests;

use DateTimeImmutable;
use DateTimeZone;
use VulcaTrack\Support\SalesHistoryFilter;

test('Sales History periods use Manila calendar days and preserve legacy bounds', function () {
    $clock = new DateTimeImmutable('2026-10-12 00:01:00', new DateTimeZone('Asia/Manila'));
    $cases = [
        [[], 'today', '2026-10-12', '2026-10-12'],
        [['period' => 'today'], 'today', '2026-10-12', '2026-10-12'],
        [['period' => 'yesterday'], 'yesterday', '2026-10-11', '2026-10-11'],
        [['period' => '7d'], '7d', '2026-10-06', '2026-10-12'],
        [['period' => '30d'], '30d', '2026-09-13', '2026-10-12'],
        [['period' => 'exact', 'day' => '2001-06-10'], 'exact', '2001-06-10', '2001-06-10'],
        [['period' => 'custom', 'from' => '2001-06-10', 'to' => '2001-06-10'], 'custom', '2001-06-10', '2001-06-10'],
        [['from' => '2001-06-10', 'to' => '2001-06-10'], 'exact', '2001-06-10', '2001-06-10'],
        [['from' => '2001-06-10', 'to' => '2001-06-11'], 'custom', '2001-06-10', '2001-06-11'],
        [['from' => '2001-06-10'], 'custom', '2001-06-10', ''],
        [['to' => '2001-06-11'], 'custom', '', '2001-06-11'],
    ];
    foreach ($cases as [$input, $period, $from, $to]) {
        $resolved = SalesHistoryFilter::resolve($input, $clock);
        assert_same([], $resolved['errors'], json_encode($input));
        assert_same($period, $resolved['period']);
        assert_same($from, $resolved['from']);
        assert_same($to, $resolved['to']);
    }
    assert_same(['period' => 'custom', 'from' => '2001-06-10', 'source' => 'rescue',
        'sort' => 'highest', 'page' => 2],
        SalesHistoryFilter::parameters(SalesHistoryFilter::resolve(
            ['from' => '2001-06-10', 'source' => 'rescue', 'sort' => 'highest'], $clock), 2));
});

test('Sales History rejects malformed filters before querying', function () {
    foreach ([
        ['period' => 'all'],
        ['period' => ['today']],
        ['period' => 'exact'],
        ['period' => 'exact', 'from' => '2001-06-10', 'to' => '2001-06-10'],
        ['period' => 'exact', 'day' => '2001-02-31'],
        ['period' => 'custom'],
        ['from' => '2001-02-31'],
        ['to' => ['2001-06-10']],
        ['from' => '2001-06-11', 'to' => '2001-06-10'],
        ['source' => 'anything'],
        ['sort' => 's.sale_id DESC'],
        ['page' => '0'],
        ['page' => '-1'],
        ['page' => '99999999999999999999'],
        ['page' => ['2']],
    ] as $input) {
        assert_true(SalesHistoryFilter::resolve($input)['errors'] !== [], json_encode($input));
    }
});
