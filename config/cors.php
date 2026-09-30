<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | This API is bearer-token only (Sanctum personal access tokens, not
    | cookie-based SPA auth — see bootstrap/app.php), so credentials
    | (cookies) never need to cross origins. That's why
    | `supports_credentials` stays false and `allowed_origins` can safely
    | be a wildcard: a bearer token is sent explicitly by the client in an
    | Authorization header on each request, not implicitly attached by the
    | browser the way a cookie would be, so a wildcard origin here does not
    | expose the API to the CSRF-style risks it would for cookie auth.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
