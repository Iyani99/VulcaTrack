<?php

namespace VulcaTrack\Support;

/**
 * One place returned by a geocoder search: a human-readable label plus the
 * coordinates it resolves to. Treated as immutable by convention (PHP 8.0 has
 * no `readonly`). Coordinates are guaranteed in range -- the geocoder drops
 * anything that fails Geo validation before constructing this.
 */
final class GeocodeResult
{
    public function __construct(
        public string $label,
        public float $latitude,
        public float $longitude
    ) {
    }

    /** @return array{label:string,latitude:float,longitude:float} */
    public function toArray(): array
    {
        return [
            'label'     => $this->label,
            'latitude'  => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
