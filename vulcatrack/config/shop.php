<?php
/**
 * VulcaTrack -- fixed shop location.
 *
 * Project Decision 37: the shop's location is centralized application
 * configuration, NOT a database table (no `shop_settings` in v1). This file is
 * the single source of truth for the shop coordinates used by the OTG
 * route / ETA calculation. All such code must read from here.
 *
 * The values below are PLACEHOLDERS. Replace with the real shop location
 * before any OTG feature work begins.
 */

return [
    'name'      => 'VulcaTrack Vulcanizing Shop',
    'address'   => 'TBD -- set the real shop address',
    'latitude'  => 0.0,
    'longitude' => 0.0,
];
