<?php

namespace VulcaTrack\Auth;

/**
 * Authentication state for a single browser session.
 *
 * Exactly one actor may be signed in per session: either a customer or an
 * admin, never both. The actor's identity lives under $_SESSION['auth'].
 *
 * Idle timeout: 'last_activity' is refreshed on every authenticated request
 * (sliding window). Once the gap exceeds the configured idle timeout the
 * session is cleared. There is no absolute session lifetime.
 */
final class Auth
{
    private const KEY = 'auth';

    private int $idleTimeout;

    public function __construct(array $config)
    {
        $this->idleTimeout = (int) ($config['session']['idle_timeout'] ?? 1800);
    }

    /**
     * Start an authenticated session. Call only after credentials are verified.
     *
     * @param string $type 'customer' or 'admin'
     */
    public function login(string $type, int $id, string $displayName): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true); // new session id — defeats fixation
        }
        $now = time();
        $_SESSION[self::KEY] = [
            'type'          => $type,
            'id'            => $id,
            'name'          => $displayName,
            'login_at'      => $now,
            'last_activity' => $now,
        ];
        Csrf::rotate();
    }

    /** Clear authentication state and issue a fresh session id. */
    public function logout(): void
    {
        unset($_SESSION[self::KEY]);
        Csrf::rotate();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * The signed-in actor of the given type, or null. Enforces the idle
     * timeout and, on success, slides the activity window forward.
     *
     * @return array{type:string,id:int,name:string,login_at:int,last_activity:int}|null
     */
    public function actor(string $type): ?array
    {
        $a = $_SESSION[self::KEY] ?? null;
        if (!is_array($a) || ($a['type'] ?? null) !== $type) {
            return null;
        }

        $last = (int) ($a['last_activity'] ?? 0);
        if ($last > 0 && (time() - $last) > $this->idleTimeout) {
            $this->logout();
            return null;
        }

        $_SESSION[self::KEY]['last_activity'] = time();
        return $a;
    }

    public function check(string $type): bool
    {
        return $this->actor($type) !== null;
    }
}
