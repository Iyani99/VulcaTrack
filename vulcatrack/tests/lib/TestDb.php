<?php
/**
 * VulcaTrack test harness -- database helpers.
 *
 * Integration tests that touch the database wrap their work in a transaction
 * that is always rolled back. Transaction-owning service tests clean their
 * committed throwaway rows in finally blocks; the test database is separate
 * from the real VulcaTrack database.
 *
 * HTTP tests run in a separate process and cannot share a transaction; they
 * track the ids they insert and delete them in FK-safe order in a shutdown
 * handler.
 */

namespace VulcaTrack\Tests;

final class TestDb
{
    /** FK-safe teardown order (children first; sales references service_requests). */
    public const TABLES_CHILD_FIRST = [
        'sale_items',
        'sales',
        'service_requests',
        'vehicles',
        'inventory_restocks',
        'inventory_adjustments',
        'items',
        'tiremen',
        'inventory_settings',
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
