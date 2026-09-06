<?php

namespace VulcaTrack\Support;

use InvalidArgumentException;

/**
 * Money handling for VulcaTrack — integer centavos, no floats (Decision 55).
 *
 * The `price` / `total_amount` columns are DECIMAL(10,2). PDO returns them as
 * strings ("99.95"). Application logic converts those strings to integer
 * centavos through this helper, does its arithmetic with plain integers
 * (add / subtract / multiply by a quantity), then formats back to a two-place
 * decimal string for storage or display.
 *
 *   Money::toCentavos('100.00')  => 10000
 *   Money::toCentavos('99.95')   => 9995
 *   Money::toCentavos('0')       => 0
 *   Money::format(9995)          => '99.95'
 *   Money::format(10000)         => '100.00'
 *
 * Malformed input (empty, non-numeric, thousands separators, scientific
 * notation, a negative amount, or more than two decimal places) is rejected.
 * Excess precision is NOT silently rounded — no project decision permits that.
 */
final class Money
{
    /** Largest amount DECIMAL(10,2) can hold, in centavos: 99,999,999.99. */
    public const MAX_CENTAVOS = 9999999999;

    /**
     * Parse a non-negative decimal money string (or a whole-peso integer) into
     * integer centavos.
     *
     * @param string|int $value
     * @throws InvalidArgumentException on malformed input
     */
    public static function toCentavos($value): int
    {
        $centavos = self::tryToCentavos($value);
        if ($centavos === null) {
            throw new InvalidArgumentException('Malformed monetary amount: ' . var_export($value, true));
        }
        return $centavos;
    }

    /**
     * Same as toCentavos() but returns null instead of throwing — for the
     * validation layer.
     *
     * @param string|int $value
     */
    public static function tryToCentavos($value): ?int
    {
        if (is_int($value)) {
            if ($value < 0 || $value > intdiv(self::MAX_CENTAVOS, 100)) {
                return null;
            }
            return $value * 100;
        }

        if (!is_string($value)) {
            return null; // floats and everything else are rejected on purpose
        }

        $s = trim($value);
        // 1..15 integer digits (keeps the *100 multiply well inside PHP_INT_MAX),
        // then at most two decimal places. No sign, no separators, no exponent.
        if ($s === '' || !preg_match('/^\d{1,15}(?:\.(\d{1,2}))?$/', $s, $m)) {
            return null;
        }

        [$whole] = explode('.', $s, 2);
        $frac = isset($m[1]) ? str_pad($m[1], 2, '0') : '00'; // "" -> "00", "5" -> "50"

        $centavos = (int) $whole * 100 + (int) $frac;

        return $centavos <= self::MAX_CENTAVOS ? $centavos : null;
    }

    /**
     * Format integer centavos back to a plain two-place decimal string.
     * A negative value (which arithmetic can produce, e.g. an intermediate
     * difference) is formatted with a leading '-'.
     */
    public static function format(int $centavos): string
    {
        $sign = $centavos < 0 ? '-' : '';
        $abs  = abs($centavos);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
