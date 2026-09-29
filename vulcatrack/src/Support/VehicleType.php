<?php

namespace VulcaTrack\Support;

/**
 * The vehicle-type choices offered by the Add / Edit Vehicle dropdown
 * (Phase 7.4b-e1). The chosen label itself is stored in `vehicles.vehicle_type`
 * (free text, no lookup table). The field stays optional: blank = NULL.
 *
 * Backward compatibility: vehicles saved before the dropdown may hold any
 * free-text type (e.g. "Motorcycle"). Such a legacy value is offered as an
 * extra option on that vehicle's own edit form and stays accepted there, so
 * saving the vehicle never silently drops it. Anything else outside the list
 * is refused server-side — the <select> is not the security boundary.
 */
final class VehicleType
{
    /** Display label = stored value. */
    public const CHOICES = [
        'Motorcycle / Scooter',
        'Tricycle',
        'Bicycle',
        'Sedan',
        'Hatchback',
        'SUV',
        'Pickup Truck',
        'Van / MPV',
        'Other',
    ];

    /** True when $value is one of the standard choices (exact match). */
    public static function isChoice(string $value): bool
    {
        return in_array($value, self::CHOICES, true);
    }

    /**
     * The value a vehicle currently stores when it is NOT a standard choice
     * (a legacy free-text type), else null.
     */
    public static function legacyValue(?string $stored): ?string
    {
        $stored = trim((string) $stored);

        return $stored === '' || self::isChoice($stored) ? null : $stored;
    }

    /**
     * Whether a submitted (already trimmed, non-blank) type may be saved:
     * a standard choice, or the vehicle's own existing legacy value.
     */
    public static function isAllowed(string $value, ?string $stored = null): bool
    {
        return self::isChoice($value) || ($value === self::legacyValue($stored));
    }

    /** Two-wheel / small-motor types get the motorcycle icon (display only). */
    public static function isTwoWheeler(?string $type): bool
    {
        return preg_match('/motor|bike|cycle|scooter/i', (string) $type) === 1;
    }

    /** Decorative inline SVG for a vehicle tile (aria-hidden). */
    public static function icon(?string $type): string
    {
        return self::isTwoWheeler($type)
            ? '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5.5" cy="16.5" r="3"/><circle cx="18.5" cy="16.5" r="3"/><path d="M5.5 16.5 9 10h5l4.5 6.5M9 10 7.5 7H5M14 10l1.5-3H18"/></svg>'
            : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 16V11l2-5h10l2 5v5M5 16h14M5 16v2.5M19 16v2.5M4 11h16"/><circle cx="8" cy="13.5" r=".6"/><circle cx="16" cy="13.5" r=".6"/></svg>';
    }
}
