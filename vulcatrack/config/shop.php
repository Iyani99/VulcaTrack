<?php
/**
 * VulcaTrack -- fixed shop location.
 *
 * Project Decision 37: the shop's location is centralized application
 * configuration, NOT a database table (no `shop_settings` in v1). This file is
 * the single source of truth for the shop coordinates used by the OTG
 * route / ETA calculation. All such code must read from here.
 */

return [
    'name'      => 'Gerald Tabayag Vulcanizing Shop',
    'address'   => '504 San Jose St. Baliwag, Bulacan',
    'latitude'  => 14.946654430279454,   
    'longitude' => 120.89290174619997, 
];
