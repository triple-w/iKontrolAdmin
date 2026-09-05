<?php

return [
    'connection' => 'fc2_legacy',
    'host' => env('FC2_DB_HOST'),
    'port' => (int) env('FC2_DB_PORT', 3306),
    'database' => env('FC2_DB_DATABASE', 'tws001_factucare'),
    'username' => env('FC2_DB_USERNAME'),
    'password' => env('FC2_DB_PASSWORD'),
    'per_page' => 20,
];
