<?php

namespace VulcaTrack\Auth;

/**
 * Per-session CSRF token. One token per session, rotated on login/logout.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf';

    /** Current token, generated on first use. */
    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            self::rotate();
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /** Hidden input markup for a form. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /** Constant-time check of a submitted token against the session token. */
    public static function check(?string $submitted): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        return is_string($submitted)
            && is_string($expected)
            && $expected !== ''
            && hash_equals($expected, $submitted);
    }

    /** Replace the token with a fresh random value. */
    public static function rotate(): void
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }
}
