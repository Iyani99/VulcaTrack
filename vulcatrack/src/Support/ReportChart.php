<?php

namespace VulcaTrack\Support;

use DateTimeImmutable;

/**
 * Pure helpers behind the admin Sales Reports chart and Sales by Source panel
 * (Phase 7.4d). No database, no output — admin/reports.php renders the SVG.
 *
 * Every figure comes from the recorded sales the page already reads; this
 * class only decides the chart's day window, fills days without sales as real
 * ₱0 days, picks a clean y-axis scale and splits revenue shares exactly.
 */
final class ReportChart
{
    /** A From–To range up to this many days (inclusive) is charted in full. */
    public const MAX_RANGE_DAYS = 92;

    /** Otherwise the chart shows this many days, ending at To (or today). */
    public const DEFAULT_DAYS = 30;

    /** The earliest day a DATETIME (and SaleRepository::isValidDay) accepts. */
    private const FIRST_DAY = '1000-01-01';

    /**
     * The chart's day window (Phase 7.4d, approved rule): both From and To set
     * and at most MAX_RANGE_DAYS days → exactly that range; anything else (no
     * filter, one side only, or a longer range) → the DEFAULT_DAYS days ending
     * at To, or at $today when To is absent. `follows_filter` says whether the
     * window is the page's own filter range, so the page can say when it is not.
     *
     * Days are valid 'YYYY-MM-DD' strings; a From later than To is the page's
     * range error and is never charted.
     *
     * @return array{from: string, to: string, days: int, follows_filter: bool}
     */
    public static function window(?string $from, ?string $to, string $today): array
    {
        if ($from !== null && $to !== null && $from <= $to) {
            $days = self::daysBetween($from, $to) + 1;
            if ($days <= self::MAX_RANGE_DAYS) {
                return ['from' => $from, 'to' => $to, 'days' => $days, 'follows_filter' => true];
            }
        }

        $end   = $to ?? $today;
        $start = (new DateTimeImmutable($end))->modify('-' . (self::DEFAULT_DAYS - 1) . ' days')->format('Y-m-d');
        if ($start < self::FIRST_DAY) { // 'Y' pads year 999 to '0999', so the string compare holds
            $start = self::FIRST_DAY;
        }

        return ['from' => $start, 'to' => $end, 'days' => self::daysBetween($start, $end) + 1, 'follows_filter' => false];
    }

    /**
     * One entry per calendar day of the window, oldest first. Days found in
     * $rows (SaleRepository::listDailyTotals() output, any order) keep their
     * recorded count and total; every other day is a real zero day.
     *
     * @param array<int,array{day: string, transaction_count: int, total_centavos: int}> $rows
     * @return array<int,array{day: string, transaction_count: int, total_centavos: int}>
     */
    public static function fillDays(array $rows, string $from, int $days): array
    {
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row['day']] = $row;
        }

        $out   = [];
        $start = new DateTimeImmutable($from);
        for ($i = 0; $i < $days; $i++) { // counted, not compared as strings (year 10000 would sort first)
            $day   = $start->modify('+' . $i . ' days')->format('Y-m-d');
            $out[] = [
                'day'               => $day,
                'transaction_count' => (int) ($byDay[$day]['transaction_count'] ?? 0),
                'total_centavos'    => (int) ($byDay[$day]['total_centavos'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * A y-axis for a largest value of $maxCentavos: a clean whole-peso step
     * (1, 2, 2.5 or 5 × a power of ten) giving about $ticks gridlines, and the
     * axis top — the first step at or above the largest value. max 0 (no sales
     * in the window) gives step 0 / max 0: the page then draws no scale.
     *
     * @return array{step: int, max: int} centavos
     */
    public static function scale(int $maxCentavos, int $ticks = 4): array
    {
        if ($maxCentavos <= 0) {
            return ['step' => 0, 'max' => 0];
        }

        $raw = max(100, intdiv($maxCentavos + $ticks - 1, $ticks)); // at least ₱1 per step
        $mag = 100;
        while ($mag * 10 <= $raw) {
            $mag *= 10;
        }
        $step = $mag * 10;
        foreach ([$mag, $mag * 2, intdiv($mag * 5, 2), $mag * 5] as $candidate) {
            if ($candidate >= $raw && $candidate % 100 === 0) { // whole pesos only (₱2.50 is skipped)
                $step = $candidate;
                break;
            }
        }

        return ['step' => $step, 'max' => intdiv($maxCentavos + $step - 1, $step) * $step];
    }

    /**
     * Which day indexes get an x-axis label: all of them up to $maxLabels days,
     * otherwise evenly spaced ones that always include the first and last day
     * (a spaced label too close to the last one is dropped so they never touch).
     *
     * @return array<int,int>
     */
    public static function labelIndexes(int $days, int $maxLabels = 6): array
    {
        if ($days <= $maxLabels) {
            return $days > 0 ? range(0, $days - 1) : [];
        }

        $step = (int) ceil(($days - 1) / ($maxLabels - 1));
        $idx  = range(0, $days - 1, $step);
        $last = $days - 1;
        if (end($idx) !== $last) {
            if ($last - end($idx) < max(2, $step / 2)) {
                array_pop($idx);
            }
            $idx[] = $last;
        }

        return $idx;
    }

    /**
     * Revenue shares of two parts as one-decimal percentages that always add
     * up to exactly 100.0 (the second is the complement of the first, rounded
     * half up in integer per-mille). Null when there is no revenue to share —
     * the page shows no percentage rather than a fake 0 % / 0 %.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function shares(int $aCentavos, int $bCentavos): ?array
    {
        $total = $aCentavos + $bCentavos;
        if ($aCentavos < 0 || $bCentavos < 0 || $total <= 0) {
            return null;
        }

        $a = intdiv($aCentavos * 2000 + $total, 2 * $total); // per mille, half up

        return [self::permille($a), self::permille(1000 - $a)];
    }

    private static function permille(int $p): string
    {
        return intdiv($p, 10) . '.' . ($p % 10);
    }

    private static function daysBetween(string $from, string $to): int
    {
        return (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days;
    }
}
