<?php

namespace VulcaTrack\Support;

/**
 * A Geocoder that matches against a fixed in-memory list -- no network.
 *
 * Two uses:
 *  - automated tests (a controlled, deterministic response), and
 *  - the `driver = none` / offline configuration, seeded with a few local
 *    landmarks so the Rescue page's landmark search still does something
 *    useful when the public Nominatim service must not be called.
 *
 * Matching is a case-insensitive "all query words appear in the label" test.
 * Coordinates that fail Geo validation are skipped at construction.
 */
final class ArrayGeocoder implements Geocoder
{
    /** @var list<GeocodeResult> */
    private array $places = [];

    /** @param iterable<array{label:string,latitude:int|float|string,longitude:int|float|string}> $places */
    public function __construct(iterable $places)
    {
        foreach ($places as $p) {
            $lat = $p['latitude'] ?? null;
            $lng = $p['longitude'] ?? null;
            if (!Geo::isValidLatitude($lat) || !Geo::isValidLongitude($lng)) {
                continue;
            }
            $this->places[] = new GeocodeResult((string) ($p['label'] ?? ''), (float) $lat, (float) $lng);
        }
    }

    public function search(string $query, int $limit = 5): array
    {
        $words = array_values(array_filter(
            preg_split('/\s+/', mb_strtolower(trim($query))) ?: [],
            static fn ($w) => $w !== ''
        ));
        if ($words === []) {
            return [];
        }
        $out = [];
        foreach ($this->places as $place) {
            $haystack = mb_strtolower($place->label);
            $allMatch = true;
            foreach ($words as $w) {
                if (mb_strpos($haystack, $w) === false) {
                    $allMatch = false;
                    break;
                }
            }
            if ($allMatch) {
                $out[] = $place;
                if (count($out) >= max(1, $limit)) {
                    break;
                }
            }
        }
        return $out;
    }
}
