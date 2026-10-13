<?php

namespace VulcaTrack\Support;

/** Date-only promotion states in the configured Asia/Manila app calendar. */
final class PromotionLifecycle
{
    public static function validDay(string $day): bool
    {
        return preg_match('/^([1-9]\d{3})-(\d{2})-(\d{2})$/D', $day, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** @param array<string,mixed> $promotion */
    public static function status(array $promotion, string $today): string
    {
        if ($promotion['cancelled_at'] !== null) {
            return 'cancelled';
        }
        if (($promotion['ends_on'] !== null && $today > $promotion['ends_on'])
            || ($promotion['unit_limit'] !== null
                && (int) $promotion['units_consumed'] >= (int) $promotion['unit_limit'])) {
            return 'ended';
        }
        return $today < $promotion['starts_on'] ? 'scheduled' : 'active';
    }

    /** @param array<string,mixed> $promotion */
    public static function remaining(array $promotion): ?int
    {
        return $promotion['unit_limit'] === null
            ? null : max(0, (int) $promotion['unit_limit'] - (int) $promotion['units_consumed']);
    }
}
