<?php
// Copy to config.local.php (ignored by Git), or place it outside the web root
// and set PFN_CONFIG_FILE to its absolute path.
return [
    'database' => [
        'host' => 'mysql.example.ne.jp',
        'port' => 3306,
        'name' => 'account_place_field_notes',
        'user' => 'account',
        'password' => 'replace-with-your-database-password',
    ],
    // Must support Overpass attic/history data and the adiff setting.
    'overpass_history_endpoint' => 'https://overpass-api.de/api/interpreter',
    // Prefer a writable directory outside the public web root.
    'upload_dir' => '/home/account/private/place-field-notes/uploads',
    'diff_cache_ttl' => 3600,
    'edit_session_ttl' => 604800,
    'max_upload_bytes' => 12582912,
];
