<?php

namespace VulcaTrack\Support;

/**
 * Presentation mapping over the four database status values
 * (`pending`, `accepted`, `rejected`, `completed` -- Decision 10).
 *
 * "Tireman is on the way" is customer-facing wording for the `accepted` state
 * once a Tireman has been assigned; it is NOT a separate status value
 * (Decisions 7/11, PROJECT-CONTEXT s14).
 */
final class OtgStatus
{
    public const VALUES = ['pending', 'accepted', 'rejected', 'completed'];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::VALUES, true);
    }

    /** Customer-facing label. */
    public static function label(string $status, bool $tiremanAssigned = false): string
    {
        switch ($status) {
            case 'pending':
                return 'Pending review';
            case 'accepted':
                return $tiremanAssigned ? 'Tireman is on the way' : 'Accepted — assigning a tireman';
            case 'rejected':
                return 'Request declined';
            case 'completed':
                return 'Completed';
            default:
                return ucfirst($status);
        }
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
