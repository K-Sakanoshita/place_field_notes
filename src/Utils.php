<?php

declare(strict_types=1);

function envValue(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

function generatePublicId(): string
{
    return rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
}

function generateEditToken(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function generateSessionToken(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function hashEditToken(string $token): string
{
    $hash = password_hash($token, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException('Could not hash edit token');
    }
    return $hash;
}

function verifyEditToken(string $token, string $hash): bool
{
    return password_verify($token, $hash);
}

function hashSessionToken(string $token): string
{
    return hash('sha256', $token);
}

function utcNow(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

function toUtc(string $datetime, string $timezone): string
{
    try {
        $tz = new DateTimeZone($timezone);
        $dt = new DateTimeImmutable($datetime, $tz);
    } catch (Throwable) {
        throw new InvalidArgumentException('Invalid datetime or timezone');
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function utcIso(string $mysqlDateTime): string
{
    return (new DateTimeImmutable($mysqlDateTime, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

function fromUtcForTimezone(string $mysqlDateTime, string $timezone): string
{
    try {
        $tz = new DateTimeZone($timezone);
    } catch (Throwable) {
        $tz = new DateTimeZone('UTC');
    }
    return (new DateTimeImmutable($mysqlDateTime, new DateTimeZone('UTC')))
        ->setTimezone($tz)->format(DateTimeInterface::ATOM);
}

function validateActivityRange(string $startUtc, string $endUtc): void
{
    $start = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));
    $end = new DateTimeImmutable($endUtc, new DateTimeZone('UTC'));
    if ($start >= $end) {
        throw new InvalidArgumentException('start_at must be earlier than end_at');
    }
    $maxHours = max(1, (int)envValue('PFN_MAX_ACTIVITY_HOURS', '168'));
    if (($end->getTimestamp() - $start->getTimestamp()) > ($maxHours * 3600)) {
        throw new InvalidArgumentException("Activity period exceeds {$maxHours} hours");
    }
}

function normalizeBbox(mixed $bbox): array
{
    if (is_string($bbox)) {
        try {
            $bbox = json_decode($bbox, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('bbox is not valid JSON');
        }
    }
    if (is_array($bbox) && isset($bbox['minLon'], $bbox['minLat'], $bbox['maxLon'], $bbox['maxLat'])) {
        $bbox = [$bbox['minLon'], $bbox['minLat'], $bbox['maxLon'], $bbox['maxLat']];
    }
    if (!is_array($bbox) || count($bbox) !== 4) {
        throw new InvalidArgumentException('bbox must contain west, south, east and north');
    }
    $values = [];
    foreach (array_values($bbox) as $value) {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('bbox contains an invalid coordinate');
        }
        $values[] = (float)$value;
    }
    [$west, $south, $east, $north] = $values;
    if ($west < -180 || $east > 180 || $south < -90 || $north > 90 || $west >= $east || $south >= $north) {
        throw new InvalidArgumentException('bbox is outside valid coordinate bounds');
    }
    $maxSpan = max(0.01, (float)envValue('PFN_MAX_BBOX_DEGREES', '1.0'));
    if (($east - $west) > $maxSpan || ($north - $south) > $maxSpan) {
        throw new InvalidArgumentException('bbox is too large');
    }
    return [$west, $south, $east, $north];
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('Invalid JSON body');
    }
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('JSON body must be an object');
    }
    return $decoded;
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function requireString(array $data, string $key, int $maxLength = 10000): string
{
    $value = trim((string)($data[$key] ?? ''));
    if ($value === '') {
        throw new InvalidArgumentException("{$key} is required");
    }
    if (mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException("{$key} is too long");
    }
    return $value;
}

function optionalString(array $data, string $key, int $maxLength = 10000): ?string
{
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return null;
    }
    $value = trim((string)$data[$key]);
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException("{$key} is too long");
    }
    return $value;
}

function optionalFloat(array $data, string $key, float $min, float $max): ?float
{
    if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
        return null;
    }
    if (!is_numeric($data[$key])) {
        throw new InvalidArgumentException("{$key} must be numeric");
    }
    $value = (float)$data[$key];
    if ($value < $min || $value > $max) {
        throw new InvalidArgumentException("{$key} is outside valid bounds");
    }
    return $value;
}

function validatedLicense(string $license): string
{
    $allowed = ['CC BY 4.0', 'CC BY-SA 4.0', 'CC0 1.0'];
    if (!in_array($license, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported photo license');
    }
    return $license;
}

function validatedHttpUrl(?string $url): ?string
{
    if ($url === null || trim($url) === '') {
        return null;
    }
    $url = trim($url);
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Invalid URL');
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('Only http and https URLs are allowed');
    }
    return $url;
}

function normalizeCommonsFile(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException('commons_file is required');
    }
    $value = preg_replace('/^https?:\/\/commons\.wikimedia\.org\/wiki\//i', '', $value) ?? $value;
    $value = rawurldecode($value);
    if (!str_starts_with(strtolower($value), 'file:')) {
        $value = 'File:' . $value;
    }
    return $value;
}

function commonsPageUrl(string $commonsFile): string
{
    return 'https://commons.wikimedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $commonsFile));
}

function commonsImageRedirectUrl(string $commonsFile): string
{
    $name = preg_replace('/^File:/i', '', $commonsFile) ?? $commonsFile;
    return 'https://commons.wikimedia.org/wiki/Special:Redirect/file/' . rawurlencode(str_replace(' ', '_', $name));
}

function isHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function cookieOptions(int $maxAge): array
{
    return [
        'expires' => time() + $maxAge,
        'path' => '/',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function baseUrl(): string
{
    $scheme = isHttpsRequest() ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function safeJsonDecode(?string $json, mixed $default = null): mixed
{
    if ($json === null || $json === '') {
        return $default;
    }
    try {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return $default;
    }
}
