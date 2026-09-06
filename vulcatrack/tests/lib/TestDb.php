<?php
/**
 * VulcaTrack test harness -- database helpers.
 *
 * Integration tests that touch the database wrap their work in a transaction
 * that is always rolled back, so the test database (which holds no application
 * rows in v1) is left byte-for-byte unchanged. Repository code under test never
 * commits or issues DDL, so a plain BEGIN / ROLLBACK is sufficient isolation.
 *
 * HTTP tests run in a separate process and cannot share a transaction; they
 * track the ids they insert and delete them in FK-safe order in a shutdown
 * handler.
 */

namespace VulcaTrack\Tests;

final class TestDb
{
    /** FK-safe teardown order (children first). */
    public const TABLES_CHILD_FIRST = [
        'service_requests',
        'sale_items',
        'sales',
        'vehicles',
        'items',
        'tiremen',
        'admins',
        'customers',
    ];

    /**
     * Run $fn inside a transaction and always roll it back afterwards.
     * Returns whatever $fn returns. Re-throws anything $fn throws (after the
     * rollback), so a failing assertion still leaves no residue.
     */
    public static function rollback(\PDO $pdo, \Closure $fn)
    {
        $pdo->beginTransaction();
        try {
            $result = $fn();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        return $result;
    }

    /** A unique e-mail address for a throwaway account. */
    public static function email(string $prefix = 'test'): string
    {
        return $prefix . '+' . bin2hex(random_bytes(6)) . '@vulcatrack.test';
    }
}
