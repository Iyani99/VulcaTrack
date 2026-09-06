<?php

namespace VulcaTrack\Support;

/**
 * A geocoding provider could not be reached or gave an unusable response.
 * "No results" is NOT this -- that is an empty array from Geocoder::search().
 */
final class GeocoderException extends \RuntimeException
{
}
