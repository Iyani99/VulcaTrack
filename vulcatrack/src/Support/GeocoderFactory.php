<?php

namespace VulcaTrack\Support;

/**
 * Builds the Geocoder the application should use, from the `geocoding` config
 * block. One place to change if the provider is ever swapped.
 *
 *   driver = 'nominatim'  -> NominatimGeocoder (public OSM service)
 *   driver = 'none'       -> ArrayGeocoder over geocoding.offline_places
 *
 * Test seam: when the environment variable VULCATRACK_GEOCODER_FAKE is set
 * (only ever set by the HTTP test suite), an ArrayGeocoder with fixed fixtures
 * is returned so end-to-end tests never touch the network. It is never set in
 * normal operation.
 */
final class GeocoderFactory
{
    /** @param array<string,mixed> $appConfig the whole config array */
    public static function fromConfig(array $appConfig): Geocoder
    {
        $cfg = $appConfig['geocoding'] ?? [];

        if (getenv('VULCATRACK_GEOCODER_FAKE')) {
            return new ArrayGeocoder(self::fakeFixtures());
        }

        $driver = (string) ($cfg['driver'] ?? 'nominatim');
        if ($driver === 'none') {
            return new ArrayGeocoder($cfg['offline_places'] ?? []);
        }

        return new NominatimGeocoder(is_array($cfg) ? $cfg : []);
    }

    /** @return list<array{label:string,latitude:float,longitude:float}> */
    public static function fakeFixtures(): array
    {
        return [
            ['label' => 'Shell, MacArthur Highway, Baliwag, Bulacan, Philippines', 'latitude' => 14.9512, 'longitude' => 120.8981],
            ['label' => 'Petron, Doña Remedios Trinidad Highway, Baliwag, Bulacan, Philippines', 'latitude' => 14.9557, 'longitude' => 120.9012],
            ['label' => 'SM City Baliwag, Baliwag, Bulacan, Philippines', 'latitude' => 14.9490, 'longitude' => 120.9160],
            ['label' => 'Baliwag Public Market, Baliwag, Bulacan, Philippines', 'latitude' => 14.9553, 'longitude' => 120.8983],
            // Hostile label -- exercises output encoding in tests. Never shipped.
            ['label' => 'Tondo <script>alert(1)</script> & "quotes", Baliwag', 'latitude' => 14.9500, 'longitude' => 120.9000],
        ];
    }
}
