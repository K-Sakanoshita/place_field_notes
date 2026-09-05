<?php

declare(strict_types=1);

final class Config
{
    public static function load(string $root): array
    {
        $config = [];
        $file = envValue('PFN_CONFIG_FILE');
        if ($file === null) {
            $candidate = $root . '/config.local.php';
            if (is_file($candidate)) {
                $file = $candidate;
            }
        }
        if ($file !== null) {
            if (!is_file($file)) {
                throw new RuntimeException('PFN_CONFIG_FILE does not exist');
            }
            $loaded = require $file;
            if (!is_array($loaded)) {
                throw new RuntimeException('Configuration file must return an array');
            }
            $config = $loaded;
        }

        $database = (array)($config['database'] ?? []);
        $database['host'] = envValue('PFN_DB_HOST', (string)($database['host'] ?? '127.0.0.1'));
        $database['port'] = (int)envValue('PFN_DB_PORT', (string)($database['port'] ?? '3306'));
        $database['name'] = envValue('PFN_DB_NAME', (string)($database['name'] ?? 'place_field_notes'));
        $database['user'] = envValue('PFN_DB_USER', (string)($database['user'] ?? ''));
        $database['password'] = envValue('PFN_DB_PASSWORD', (string)($database['password'] ?? ''));

        return [
            'database' => $database,
            'overpass_history_endpoint' => envValue(
                'OVERPASS_HISTORY_ENDPOINT',
                (string)($config['overpass_history_endpoint'] ?? 'https://overpass-api.de/api/interpreter')
            ),
            'diff_cache_ttl' => max(60, (int)envValue('PFN_DIFF_CACHE_TTL', (string)($config['diff_cache_ttl'] ?? '3600'))),
            'edit_session_ttl' => max(3600, (int)envValue('PFN_EDIT_SESSION_TTL', (string)($config['edit_session_ttl'] ?? '604800'))),
            'upload_dir' => envValue('PFN_UPLOAD_DIR', (string)($config['upload_dir'] ?? ($root . '/storage/uploads'))),
            'max_upload_bytes' => max(1024 * 1024, (int)envValue('PFN_MAX_UPLOAD_BYTES', (string)($config['max_upload_bytes'] ?? (12 * 1024 * 1024)))),
            'auto_migrate' => envValue('PFN_AUTO_MIGRATE', '1') !== '0',
        ];
    }
}
