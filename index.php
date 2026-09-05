<?php
/**
 * Minimal PHP router for the Place Field Notes backend.
 */

declare(strict_types=1);

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Utils.php';
require_once __DIR__ . '/src/ProjectController.php';
// Load environment variables (dotenv is optional, but we read directly)
$databasePath = getenv('PLACE_FIELD_NOTES_DB_PATH') ?: __DIR__ . '/place_field_notes.sqlite';
$db = new Database($databasePath);
$projectCtrl = new ProjectController($db);

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files from frontend/public if they exist
$publicDir = __DIR__ . '/frontend/public';
$uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Serve index.html for the root path
if ($uriPath === '/' || $uriPath === '') {
    $indexPath = $publicDir . '/index.html';
    if (is_file($indexPath)) {
        header('Content-Type: text/html');
        readfile($indexPath);
        exit;
    }
}
$fullPath = realpath($publicDir . $uriPath);
if ($fullPath && strpos($fullPath, $publicDir) === 0 && is_file($fullPath)) {
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mimeMap = [
        'js'   => 'application/javascript',
        'css'  => 'text/css',
        'html' => 'text/html',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'woff2'=> 'font/woff2',
        'woff' => 'font/woff',
        'ttf'  => 'font/ttf',
        'otf'  => 'font/otf',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
    ];
    $mime = $mimeMap[$ext] ?? mime_content_type($fullPath);
    header("Content-Type: $mime");
    readfile($fullPath);
    exit;
}

// Simple routing table
switch (true) {
    case $method === 'GET' && $uri === '/api/health':
        jsonResponse(['status' => 'ok']);
        break;
    case $method === 'POST' && $uri === '/api/projects':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) {
            jsonResponse(['error' => 'Invalid JSON'], 400);
        }
        try {
            $proj = $projectCtrl->create($body);
            jsonResponse($proj, 201);
        } catch (Throwable $e) {
            jsonResponse(['error' => $e->getMessage()], 400);
        }
        break;
    case $method === 'GET' && preg_match('#^/api/projects/(?P<id>[a-zA-Z0-9]+)$#', $uri, $m):
        $proj = $projectCtrl->getByPublicId($m['id']);
        if (!$proj) {
            jsonResponse(['error' => 'Not found'], 404);
        }
        jsonResponse($proj);
        break;
    case $method === 'POST' && $uri === '/api/osm-diff':
        // This is a placeholder implementation for the diff preview endpoint.
        // In a real application, this should call the Overpass API and
        // return a GeoJSON with the differences.  For now, return an empty
        // FeatureCollection to avoid the JSON parse error.
        $body = json_decode(file_get_contents('php://input'), true);
        // TODO: Validate input and generate real diff data.
        $response = [
            'geojson' => ['type' => 'FeatureCollection', 'features' => []],
            'candidates' => [],
        ];
        jsonResponse($response);
        break;

    default:
        jsonResponse(['error' => 'Not found'], 404);
}
