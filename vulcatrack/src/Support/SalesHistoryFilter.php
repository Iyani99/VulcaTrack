<?php

namespace VulcaTrack\Support;

use DateTimeImmutable;
use DateTimeZone;
use VulcaTrack\Repository\SaleRepository;

/** Validated, calendar-day filters for the operational Sales History screen. */
final class SalesHistoryFilter
{
    public const PAGE_SIZE = 25;

    /** @return array<string,mixed> */
    public static function resolve(array $query, ?DateTimeImmutable $clock = null): array
    {
        $today = ($clock ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
            ->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');
        $errors = [];
        $values = [];
        foreach (['period', 'day', 'from', 'to', 'source', 'sort', 'page'] as $key) {
            $raw = $query[$key] ?? null;
            if ($raw !== null && !is_string($raw)) {
                $errors[] = ucfirst($key) . ' must be a single value.';
                $raw = null;
            }
            $values[$key] = $raw;
        }

        $period = $values['period'];
        $legacyExact = $period === null && $values['from'] !== null && $values['from'] !== ''
            && $values['from'] === $values['to'];
        if ($period === null && ($values['from'] !== null || $values['to'] !== null)) {
            $period = $legacyExact ? 'exact' : 'custom';
        }
        $period ??= 'today';
        if (!in_array($period, ['today', 'yesterday', '7d', '30d', 'exact', 'custom'], true)) {
            $errors[] = 'Choose a valid period.';
            $period = 'today';
        }
        $source = $values['source'] ?? 'all';
        if (!in_array($source, ['all', 'in_shop', 'rescue'], true)) {
            $errors[] = 'Choose a valid source.';
            $source = 'all';
        }
        $sort = $values['sort'] ?? 'newest';
        if (!in_array($sort, ['newest', 'oldest', 'highest', 'lowest'], true)) {
            $errors[] = 'Choose a valid sort order.';
            $sort = 'newest';
        }
        $rawPage = $values['page'] ?? '1';
        if (!preg_match('/^[1-9][0-9]*$/D', $rawPage) || strlen($rawPage) > 15) {
            $errors[] = 'Page must be a positive whole number.';
            $rawPage = '1';
        }
        $page = (int) $rawPage;

        foreach (['day', 'from', 'to'] as $key) {
            if ($values[$key] !== null && $values[$key] !== '' && !SaleRepository::isValidDay($values[$key])) {
                $errors[] = ucfirst($key) . ' must be a valid YYYY-MM-DD date.';
            }
        }
        $day = $values['day'] ?? '';
        $from = $values['from'] ?? '';
        $to = $values['to'] ?? '';
        switch ($period) {
            case 'today':
                $from = $to = $today;
                break;
            case 'yesterday':
                $from = $to = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
                break;
            case '7d':
            case '30d':
                $from = (new DateTimeImmutable($today))->modify($period === '7d' ? '-6 days' : '-29 days')->format('Y-m-d');
                $to = $today;
                break;
            case 'exact':
                $day = $day !== '' ? $day : ($legacyExact ? $from : '');
                if ($day === '') {
                    $errors[] = 'Select an exact day.';
                }
                $from = $to = $day;
                break;
            case 'custom':
                if ($from === '' && $to === '') {
                    $errors[] = 'Select a From or To date.';
                }
                if ($from !== '' && $to !== '' && $from > $to) {
                    $errors[] = 'The From date cannot be later than the To date.';
                }
                break;
        }
        $labels = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => '7 Days',
            '30d' => '30 Days', 'exact' => 'Exact Day', 'custom' => 'Custom'];
        $range = $from !== '' && $to !== ''
            ? ($from === $to ? $from : $from . ' to ' . $to)
            : ($from !== '' ? 'From ' . $from : 'Through ' . $to);
        return compact('period', 'source', 'sort', 'page', 'day', 'from', 'to', 'errors', 'range')
            + ['label' => $labels[$period]];
    }

    /** @param array<string,mixed> $filter @return array<string,string|int> */
    public static function parameters(array $filter, ?int $page = null): array
    {
        $params = ['period' => $filter['period']];
        if ($filter['period'] === 'exact') { $params['day'] = $filter['day']; }
        if ($filter['period'] === 'custom') {
            if ($filter['from'] !== '') { $params['from'] = $filter['from']; }
            if ($filter['to'] !== '') { $params['to'] = $filter['to']; }
        }
        if ($filter['source'] !== 'all') { $params['source'] = $filter['source']; }
        if ($filter['sort'] !== 'newest') { $params['sort'] = $filter['sort']; }
        if ($page !== null && $page > 1) { $params['page'] = $page; }
        return $params;
    }
}
