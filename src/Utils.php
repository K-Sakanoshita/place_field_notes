<?php
/**
 * Helper functions used across the API.
 */

declare(strict_types=1);

function generatePublicId(): string
{
    // 6 character base62 short id
    $bytes = random_bytes(4);
    return substr(base64_encode($bytes), 0, 6);
}

function generateEditToken(): string
{
    return bin2hex(random_bytes(32)); // 64 hex chars
}

function hashEditToken(string $token, string $salt = null): string
{
    $salt ??= getenv('TOKEN_SALT') ?: 'PLACE_FIELD_NOTES_DEFAULT_SALT';
    return hash('sha256', $salt . $token);
}

/**
 * Convert datetime string with timezone to UTC ISO8601 string.
 */
function toUtc(string $datetime, string $timezone): string
{
    $dt = new DateTimeImmutable($datetime, new DateTimeZone($timezone));
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

/**
 * Simple JSON response helper.
 */
function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    // Ensure that API responses are treated as JSON and allow same-origin
    header('Content-Type: application/json');
    // Enable simple cross‑origin requests for development convenience.
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
