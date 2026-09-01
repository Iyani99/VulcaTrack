<?php

namespace VulcaTrack\Support;

/**
 * Geo helpers for the On-the-Go feature.
 *
 * The ETA is a ONE-TIME snapshot taken at request submission (Decisions 5/6/32):
 * straight-line distance between the customer's captured location and the fixed
 * shop location, divided by an assumed average speed, rounded up, with a floor.
 * It is stored in `service_requests.eta_minutes` and never recomputed for
 * display. No routing service, no polyline, no live recalculation.
 */
final class Geo
{
    private const EARTH_RADIUS_KM = 6371.0088;

    public static function isValidLatitude($value): bool
    {
        return is_numeric($value) && (float) $value >= -90.0 && (float) $value <= 90.0;
    }

    public static function isValidLongitude($value): bool
    {
        return is_numeric($value) && (float) $value >= -180.0 && (float) $value <= 180.0;
    }

    /** Great-circle distance in kilometres. */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Frozen ETA in whole minutes for the given distance.
     *
     * @param float $speedKmph    assumed average speed (config: otg.average_speed_kmph)
     * @param int   $minMinutes   floor (config: otg.min_eta_minutes)
     */
    public static function etaMinutes(float $distanceKm, float $speedKmph, int $minMinutes): int
    {
        if ($speedKmph <= 0) {
            return $minMinutes;
        }
        $minutes = (int) ceil(($distanceKm / $speedKmph) * 60);

        return max($minMinutes, $minutes);
    }
}
