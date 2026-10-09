<?php

namespace VulcaTrack\Support;

use DateTimeImmutable;

/** A single, validated sales-date window shared by Dashboard trend and Reports. */
final class ReportPeriod
{
    public const LABELS = [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '365d' => 'Last 365 days',
        'month' => 'Specific month',
        'today' => 'Today',
    ];

    /** @return array{period:string,month:?string,from:string,to:string,days:int,label:string,monthly:bool} */
    public static function resolve($period, $month, string $today, string $default = '7d', bool $allowToday = false): array
    {
        $period = is_string($period) && isset(self::LABELS[$period]) ? $period : $default;
        if ($period === 'today' && !$allowToday) { $period = $default; }
        $todayDate = new DateTimeImmutable($today);
        $selectedMonth = null;
        if ($period === 'month') {
            if (!is_string($month) || preg_match('/^([1-9]\d{3})-(0[1-9]|1[0-2])$/D', $month, $match) !== 1
                || (int) $match[1] > 9999) {
                $period = $default;
            } else {
                $selectedMonth = $month;
            }
        }

        if ($selectedMonth !== null) {
            $fromDate = new DateTimeImmutable($selectedMonth . '-01');
            $toDate = $fromDate->modify('last day of this month');
        } else {
            $days = ['today' => 1, '7d' => 7, '30d' => 30, '365d' => 365][$period];
            $fromDate = $todayDate->modify('-' . ($days - 1) . ' days');
            $toDate = $todayDate;
        }

        return [
            'period' => $period,
            'month' => $selectedMonth,
            'from' => $fromDate->format('Y-m-d'),
            'to' => $toDate->format('Y-m-d'),
            'days' => (int) $fromDate->diff($toDate)->days + 1,
            'label' => $selectedMonth !== null ? $fromDate->format('M Y') : self::LABELS[$period],
            'monthly' => $period === '365d',
        ];
    }

    /** @return array<int,string> Every month from first recorded sale through the current/sold latest month. */
    public static function monthOptions(array $recordedMonths, string $today, ?string $selected): array
    {
        $current = substr($today, 0, 7);
        $months = array_fill_keys($recordedMonths, true);
        $recentStart = (new DateTimeImmutable($current . '-01'))->modify('-11 months')->format('Y-m');
        $first = $recordedMonths ? min(min($recordedMonths), $recentStart) : $recentStart;
        $last = $recordedMonths ? max(max($recordedMonths), $current) : $current;
        $cursor = new DateTimeImmutable($first . '-01');
        while ($cursor->format('Y-m') <= $last) {
            $months[$cursor->format('Y-m')] = true;
            if ($cursor->format('Y-m') === $last) { break; }
            $cursor = $cursor->modify('+1 month');
        }
        if ($selected !== null) {
            $months[$selected] = true;
        }
        $out = array_keys($months);
        rsort($out, SORT_STRING);
        return $out;
    }
}
