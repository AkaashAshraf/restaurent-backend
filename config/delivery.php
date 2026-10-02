<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Delivery zones
    |--------------------------------------------------------------------------
    |
    | Off for now: delivery zones are not enforced, so a customer can order
    | delivery from anywhere (the restaurant's normal delivery charge applies),
    | and the zone editor is hidden in the admin panel. The zone data and the
    | zone API are untouched — set DELIVERY_ZONES_ENABLED=true in .env (then
    | `php artisan optimize:clear`) to bring zones back exactly as they were.
    |
    */
    'zones_enabled' => (bool) env('DELIVERY_ZONES_ENABLED', false),

];
