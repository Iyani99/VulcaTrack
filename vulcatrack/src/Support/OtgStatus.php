<?php

namespace VulcaTrack\Support;

/**
 * Presentation mapping over the four database status values
 * (`pending`, `accepted`, `rejected`, `completed` -- Decision 10).
 *
 * "Tireman is on the way" is customer-facing wording for the `accepted` state
 * once a Tireman has been assigned; it is NOT a separate status value
 * (Decisions 7/11, PROJECT-CONTEXT s14).
 *
 * Also holds the allowed admin status transitions (canTransition) and which
 * statuses may have a sale recorded (canRecordSale). The repository's guarded
 * UPDATEs and SaleService enforce the same rules at the database.
 */
final class OtgStatus
{
    public const VALUES = ['pending', 'accepted', 'rejected', 'completed'];

    /**
     * The only status changes an admin may make (owner-approved, Phase 6.3).
     * `rejected` and `completed` are final: nothing leaves them.
     */
    private const TRANSITIONS = [
        'pending'  => ['accepted', 'rejected'],
        'accepted' => ['completed', 'rejected'],
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::VALUES, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** A final status can never be changed again. */
    public static function isFinal(string $status): bool
    {
        return $status === 'rejected' || $status === 'completed';
    }

    /**
     * Whether a sale may be recorded for a request in this status (Phase 7.3d,
     * owner-approved): accepted (the normal case) or completed (a late entry
     * after the request was closed). Never pending (not yet accepted) or
     * rejected. Recording a sale does not change the status.
     */
    public static function canRecordSale(string $status): bool
    {
        return $status === 'accepted' || $status === 'completed';
    }

    /**
     * Customer-facing label. Accepting a request assigns an active Tireman in
     * the same admin action (Decision 66), so "accepted without a Tireman" is
     * only a defensive fallback for malformed / legacy data — labelled
     * neutrally, never as an assignment still in progress.
     */
    public static function label(string $status, bool $tiremanAssigned = false): string
    {
        switch ($status) {
            case 'pending':
                return 'Pending review';
            case 'accepted':
                return $tiremanAssigned ? 'Tireman is on the way' : 'Accepted request';
            case 'rejected':
                return 'Request declined';
            case 'completed':
                return 'Completed';
            default:
                return ucfirst($status);
        }
    }

    /** Plain admin-facing label ("Pending", "Accepted", ...) — no customer wording. */
    public static function adminLabel(string $status): string
    {
        return ucfirst($status);
    }

    /** CSS modifier class for the status badge. */
    public static function badgeClass(string $status): string
    {
        switch ($status) {
            case 'accepted':
                return 'badge--accepted';
            case 'rejected':
                return 'badge--rejected';
            case 'completed':
                return 'badge--completed';
            case 'pending':
            default:
                return 'badge--pending';
        }
    }
}
