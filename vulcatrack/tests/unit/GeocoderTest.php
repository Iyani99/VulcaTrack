<?php
/**
 * Unit tests for the landmark/address geocoding layer (Phase 4 enhancement).
 * No network: NominatimGeocoder's HTTP call is injected as a callable.
 */

namespace VulcaTrack\Tests;

use VulcaTrack\Support\ArrayGeocoder;
use VulcaTrack\Support\GeocodeResult;
use VulcaTrack\Support\Geocoder;
use VulcaTrack\Support\GeocoderException;
use VulcaTrack\Support\GeocoderFactory;
use VulcaTrack\Support\NominatimGeocoder;

/** A canned Nominatim jsonv2 body. */
function nominatim_body(): string
{
    return json_encode([
        ['display_name' => 'Shell, MacArthur Highway, Baliwag, Bulacan, Philippines', 'lat' => '14.9512', 'lon' => '120.8981', 'type' => 'fuel'],
        ['display_name' => 'SM City Baliwag, Baliwag, Bulacan, Philippines', 'lat' => '14.9490', 'lon' => '120.9160', 'type' => 'mall'],
    ]);
}

function nominatim_geocoder(string $body, int $status = 200, ?array &$capturedUrl = null): NominatimGeocoder
{
    $cfg = [
        'endpoint'      => 'https://nominatim.openstreetmap.org/search',
        'user_agent'    => 'VulcaTrack/test',
        'country_codes' => 'ph',
        'viewbox'       => '120.55,15.20,121.15,14.60',
        'bounded'       => false,
    ];
    return new NominatimGeocoder($cfg, function (string $url) use ($body, $status, &$capturedUrl) {
        $capturedUrl = $url;
        return ['status' => $status, 'body' => $body];
    });
}

test('NominatimGeocoder parses a jsonv2 body into GeocodeResults', function () {
    $g = nominatim_geocoder(nominatim_body());
    $results = $g->search('Shell Baliwag', 5);

    assert_count(2, $results);
    assert_true($results[0] instanceof GeocodeResult);
    assert_contains('Shell', $results[0]->label);
    assert_same(14.9512, $results[0]->latitude);
    assert_same(120.8981, $results[0]->longitude);
});

test('NominatimGeocoder sends countrycodes + viewbox bias and a bounded flag', function () {
    $url = null;
    $g = nominatim_geocoder(nominatim_body(), 200, $url);
    $g->search('anything', 3);

    assert_contains('countrycodes=ph', $url);
    assert_contains('viewbox=', $url);
    assert_contains('bounded=0', $url);
    assert_contains('format=jsonv2', $url);
    assert_contains('limit=3', $url);
});

test('NominatimGeocoder drops results with out-of-range or missing coordinates', function () {
    $body = json_encode([
        ['display_name' => 'Good place', 'lat' => '14.95', 'lon' => '120.90'],
        ['display_name' => 'Bad lat', 'lat' => '999', 'lon' => '120.90'],
        ['display_name' => 'Bad lon', 'lat' => '14.95', 'lon' => 'not-a-number'],
        ['display_name' => 'No coords'],
    ]);
    $results = nominatim_geocoder($body)->search('x', 10);
    assert_count(1, $results);
    assert_same('Good place', $results[0]->label);
});

test('NominatimGeocoder returns [] for a blank query without calling the provider', function () {
    $called = false;
    $g = new NominatimGeocoder(['user_agent' => 'x'], function () use (&$called) {
        $called = true;
        return ['status' => 200, 'body' => '[]'];
    });
    assert_same([], $g->search('   ', 5));
    assert_false($called, 'a blank query must not hit the network');
});

test('NominatimGeocoder throws GeocoderException on a non-200 response', function () {
    assert_throws(fn () => nominatim_geocoder('Too Many Requests', 429)->search('x'), GeocoderException::class);
});

test('NominatimGeocoder throws GeocoderException on a non-array body', function () {
    assert_throws(fn () => nominatim_geocoder('<html>error</html>')->search('x'), GeocoderException::class);
});

test('NominatimGeocoder honours the result limit', function () {
    $g = nominatim_geocoder(nominatim_body());
    assert_count(1, $g->search('Baliwag', 1));
});

test('NominatimGeocoder falls back to a coordinate label when display_name is missing', function () {
    $body = json_encode([['lat' => '14.95', 'lon' => '120.90']]);
    $results = nominatim_geocoder($body)->search('x', 5);
    assert_count(1, $results);
    assert_contains('14.95', $results[0]->label);
});

test('ArrayGeocoder matches case-insensitively, honours the limit, and skips bad fixtures', function () {
    $g = new ArrayGeocoder([
        ['label' => 'Shell Baliwag', 'latitude' => 14.95, 'longitude' => 120.90],
        ['label' => 'Petron Baliwag', 'latitude' => 14.96, 'longitude' => 120.91],
        ['label' => 'Broken', 'latitude' => 999, 'longitude' => 0],
    ]);
    assert_count(2, $g->search('baliwag', 5));
    assert_count(1, $g->search('SHELL', 5));
    assert_same([], $g->search('nowhere', 5));
    assert_same([], $g->search('  ', 5));
    assert_count(1, $g->search('baliwag', 1));
});

test('GeocoderFactory returns a NominatimGeocoder by default and ArrayGeocoder for driver=none', function () {
    $nom = GeocoderFactory::fromConfig(['geocoding' => ['driver' => 'nominatim', 'user_agent' => 'x']]);
    assert_true($nom instanceof NominatimGeocoder);

    $none = GeocoderFactory::fromConfig(['geocoding' => [
        'driver' => 'none',
        'offline_places' => [['label' => 'Local Church', 'latitude' => 14.95, 'longitude' => 120.90]],
    ]]);
    assert_true($none instanceof ArrayGeocoder);
    assert_count(1, $none->search('church', 5));
});

test('GeocoderFactory honours the VULCATRACK_GEOCODER_FAKE test seam', function () {
    putenv('VULCATRACK_GEOCODER_FAKE=1');
    try {
        $g = GeocoderFactory::fromConfig(['geocoding' => ['driver' => 'nominatim', 'user_agent' => 'x']]);
        assert_true($g instanceof ArrayGeocoder, 'the fake seam must override the real driver');
        assert_true(count($g->search('Baliwag', 5)) > 0);
    } finally {
        putenv('VULCATRACK_GEOCODER_FAKE');
    }
});

test('a Geocoder is substitutable via the interface', function () {
    $fake = new ArrayGeocoder([['label' => 'X', 'latitude' => 1, 'longitude' => 2]]);
    assert_true($fake instanceof Geocoder);
});
