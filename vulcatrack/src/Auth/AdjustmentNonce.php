<?php

namespace VulcaTrack\Auth;

/** One-use, Product-bound adjustment submissions in the Admin session. */
final class AdjustmentNonce
{
    private const KEY = '_adjustment_nonces';
    private const LIFETIME = 3600;
    private const MAX_PENDING = 20;

    public static function issue(int $itemId): string
    {
        self::prune();
        while (count($_SESSION[self::KEY]) >= self::MAX_PENDING) {
            array_shift($_SESSION[self::KEY]);
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::KEY][$token] = ['item_id' => $itemId, 'created_at' => time()];
        return $token;
    }

    public static function valid($submitted, int $itemId): bool
    {
        self::prune();
        return is_string($submitted) && preg_match('/\A[a-f0-9]{64}\z/D', $submitted) === 1
            && isset($_SESSION[self::KEY][$submitted])
            && $_SESSION[self::KEY][$submitted]['item_id'] === $itemId;
    }

    public static function consume($submitted, int $itemId): bool
    {
        if (!self::valid($submitted, $itemId)) { return false; }
        unset($_SESSION[self::KEY][$submitted]);
        return true;
    }

    private static function prune(): void
    {
        if (!isset($_SESSION[self::KEY]) || !is_array($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = [];
            return;
        }
        $now = time();
        foreach ($_SESSION[self::KEY] as $token => $entry) {
            if (!is_array($entry) || !isset($entry['item_id'], $entry['created_at'])
                || !is_int($entry['item_id']) || !is_int($entry['created_at'])
                || $entry['created_at'] <= $now - self::LIFETIME) {
                unset($_SESSION[self::KEY][$token]);
            }
        }
    }
}
