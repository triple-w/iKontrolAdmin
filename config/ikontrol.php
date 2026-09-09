<?php

return [
    'db' => [
        'host' => env('IKONTROL_DB_HOST', 'localhost'),
        'port' => (int) env('IKONTROL_DB_PORT', 3306),
        'username' => env('IKONTROL_DB_USERNAME'),
        'password' => env('IKONTROL_DB_PASSWORD'),
        'prefix' => env('IKONTROL_DB_PREFIX', 'tws001_ik_'),
        'table_prefix' => env('IKONTROL_DB_TABLE_PREFIX', 'ikontrol_'),
    ],
    'instances_root' => env('IKONTROL_INSTANCES_ROOT', '/home/tws001'),
    'folder_suffix' => env('IKONTROL_FOLDER_SUFFIX', '.ikontrol.solutions'),
    'version_sources' => [
        'archive_root' => env('IKONTROL_VERSION_ARCHIVE_ROOT', storage_path('ikontrol-versions')),
    ],
    'templates' => [
        'root' => env('IKONTROL_TEMPLATE_ROOT', storage_path('ikontrol-templates')),
        'mysql_binary' => env('IKONTROL_MYSQL_BINARY', 'mysql'),
        'table_classification' => [
            'structure_only' => [],
            'keep_data' => [],
            'empty_data' => [],
        ],
    ],
    'deployment' => [
        'command_timeout' => (int) env('IKONTROL_DEPLOYMENT_TIMEOUT', 300),
        'php_binary' => env('IKONTROL_PHP_BINARY', PHP_BINARY),
        'diagnostic_tools_version' => '1.0.0',
        // Contracts are keyed by the template schema_version. Undefined schemas are reported, never guessed.
        'schema_tables' => [
            'rise-administrative-baseline-1' => ['users', 'roles', 'team', 'settings', 'dashboards', 'custom_widgets'],
        ],
    ],
    'cpanel' => [
        'host' => env('CPANEL_HOST'),
        'port' => (int) env('CPANEL_PORT', 2083),
        'username' => env('CPANEL_USERNAME'),
        'token' => env('CPANEL_API_TOKEN'),
    ],
];
