<?php
/**
 * VulcaTrack -- fixed shop location.
 *
 * Project Decision 37: the shop's location is centralized application
 * configuration, NOT a database table (no `shop_settings` in v1). This file is
 * the single source of truth for the shop coordinates used by the OTG
 * route / ETA calculation. All such code must read from here.
 *
 * The values below are SAMPLE values (a generic Cebu City point) so the OTG
 * feature is demonstrable. **Replace `latitude` / `longitude` / `address` with
 * the real shop location before a real deployment or the graded demo.**
 * Changing them here is all that is required -- no code or database change.
 */

return [
    'name'      => 'VulcaTrack Vulcanizing Shop',
    'address'   => 'Sample address -- replace with the real shop address',
    'latitude'  => 10.3157,   // SAMPLE -- replace with the real shop latitude
    'longitude' => 123.8854,  // SAMPLE -- replace with the real shop longitude
];
