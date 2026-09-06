<?php

namespace VulcaTrack\Support;

/**
 * Geocoder backed by the public OpenStreetMap Nominatim service.
 *
 * This class is deliberately just "build the request, parse the response". The
 * Nominatim Acceptable-Use Policy obligations that live around it -- a valid
 * identifying User-Agent, at most 1 request/second, caching identical queries,
 * user-triggered only (no autocomplete) -- are enforced by the single caller,
 * `customer/geocode.php`, via GeocodeCache. Attribution is rendered on the
 * Rescue page.
 *
 * The outbound HTTP call is injected as a callable so the parser can be unit
 * tested with a canned body and no network:
 *
 *   $g = new NominatimGeocoder($config['geocoding'], function (string $url) {
 *       return ['status' => 200, 'body' => $cannedJson];
 *   });
 */
final class NominatimGeocoder implements Geocoder
{
    /** @var callable(string):array{status:int,body:string} */
    private $httpGet;

    /** @param array<string,mixed> $config the config 'geocoding' block */
    public function __construct(
        private array $config,
        ?callable $httpGet = null
    ) {
        $this->httpGet = $httpGet ?? [$this, 'curlGet'];
    }

    public function search(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $limit = max(1, min($limit, 10));

        $params = [
            'q'              => $query,
            'format'         => 'jsonv2',
            'limit'          => (string) $limit,
            'addressdetails' => '0',
            'accept-language' => 'en',
        ];
        if (!empty($this->config['country_codes'])) {
            $params['countrycodes'] = (string) $this->config['country_codes'];
        }
        if (!empty($this->config['viewbox'])) {
            $params['viewbox'] = (string) $this->config['viewbox'];
            $params['bounded'] = !empty($this->config['bounded']) ? '1' : '0';
        }
        if (!empty($this->config['email'])) {
            $params['email'] = (string) $this->config['email'];
        }

        $endpoint = (string) ($this->config['endpoint'] ?? 'https://nominatim.openstreetmap.org/search');
        $url = $endpoint . '?' . http_build_query($params);

        $response = ($this->httpGet)($url);
        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($status !== 200) {
            throw new GeocoderException("Nominatim returned HTTP {$status}.");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new GeocoderException('Nominatim response was not a JSON array.');
        }

        $results = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['lat'], $row['lon'])) {
                continue;
            }
            $lat = $row['lat'];
            $lng = $row['lon'];
            // Treat every field as untrusted external data.
            if (!Geo::isValidLatitude($lat) || !Geo::isValidLongitude($lng)) {
                continue;
            }
            $label = isset($row['display_name']) && is_string($row['display_name'])
                ? trim($row['display_name'])
                : '';
            if ($label === '') {
                $label = number_format((float) $lat, 5) . ', ' . number_format((float) $lng, 5);
            }
            $results[] = new GeocodeResult($label, (float) $lat, (float) $lng);
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Default transport: cURL with the identifying User-Agent the Nominatim
     * policy requires (browsers cannot set one, which is the main reason this
     * runs server-side).
     *
     * @return array{status:int,body:string}
     */
    private function curlGet(string $url): array
    {
        $ua = (string) ($this->config['user_agent'] ?? '');
        if ($ua === '') {
            throw new GeocoderException('geocoding.user_agent is not configured.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => (int) ($this->config['timeout'] ?? 6),
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        if (!empty($this->config['referer'])) {
            curl_setopt($ch, CURLOPT_REFERER, (string) $this->config['referer']);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new GeocoderException('Could not reach the geocoding service: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body];
    }
}
