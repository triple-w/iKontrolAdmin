<?php

return [
    'db' => [
        'host' => env('IKONTROL_DB_HOST', 'localhost'),
        'port' => (int) env('IKONTROL_DB_PORT', 3306),
        'username' => env('IKONTROL_DB_USERNAME'),
        'password' => env('IKONTROL_DB_PASSWORD'),
        'prefix' => env('IKONTROL_DB_PREFIX', 'tws001_ik_'),
    ],
    'instances_root' => env('IKONTROL_INSTANCES_ROOT', '/home/tws001'),
    'folder_suffix' => env('IKONTROL_FOLDER_SUFFIX', '.ikontrol.solutions'),
    'version_sources' => [
        'archive_root' => env('IKONTROL_VERSION_ARCHIVE_ROOT', storage_path('ikontrol-versions')),
    ],
    'deployment' => [
        'command_timeout' => (int) env('IKONTROL_DEPLOYMENT_TIMEOUT', 300),
    ],
    'cpanel' => [
        'host' => env('CPANEL_HOST'),
        'port' => (int) env('CPANEL_PORT', 2083),
        'username' => env('CPANEL_USERNAME'),
        'token' => env('CPANEL_API_TOKEN'),
    ],
];
