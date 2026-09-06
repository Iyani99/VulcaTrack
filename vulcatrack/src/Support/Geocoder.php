<?php

namespace VulcaTrack\Support;

/**
 * Turns a free-text landmark / address query into a short list of candidate
 * places, each with coordinates. This is only a friendlier way for a customer
 * to pick a latitude / longitude for an OTG request -- it does not change the
 * OTG model (the request still stores lat/lng and a frozen one-time ETA).
 *
 * Implementations must return coordinates already validated in range and must
 * not throw for "no results" -- that is an empty array. A provider failure
 * (network, HTTP error, unparseable body) should throw GeocoderException so the
 * caller can degrade gracefully without ever creating an invalid request.
 */
interface Geocoder
{
    /**
     * @param  string $query  the customer's landmark / address text
     * @param  int    $limit  maximum results to return
     * @return list<GeocodeResult>  best-first; empty when nothing matched
     *
     * @throws GeocoderException on a provider / transport failure
     */
    public function search(string $query, int $limit = 5): array;
}
