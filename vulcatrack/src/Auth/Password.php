<?php

namespace VulcaTrack\Auth;

/**
 * Thin wrapper over PHP's password_* functions. No custom crypto.
 */
final class Password
{
    /**
     * A valid bcrypt hash of a random string, used only by fakeVerify() so the
     * "no such account" branch of a login does roughly the same work as a real
     * verification (blunts timing-based user enumeration). It matches no password.
     */
    private const DUMMY_HASH = '$2y$10$PMPnIv93aj8L2zSplsjTzufu/3bHnXvGjkN.WJ/VBl4z3.5Y0SuES';

    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /** Spend comparable time when an account was not found. Result is discarded. */
    public static function fakeVerify(string $plain): void
    {
        password_verify($plain, self::DUMMY_HASH);
    }
}
