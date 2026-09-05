<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Utils.php';
require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/EditSession.php';
require_once __DIR__ . '/src/OsmDiffController.php';
require_once __DIR__ . '/src/ProjectController.php';
require_once __DIR__ . '/src/PhotoController.php';

try {
    $config = Config::load(__DIR__);
    $database = new Database($config['database'], (bool)$config['auto_migrate']);
    $pdo = $database->pdo();
    $diffs = new OsmDiffController($pdo, (string)$config['overpass_history_endpoint'], (int)$config['diff_cache_ttl']);
    $sessions = new EditSession($pdo, (int)$config['edit_session_ttl']);
    $projects = new ProjectController($database, $diffs);
    $photos = new PhotoController($pdo, (string)$config['upload_dir'], (int)$config['max_upload_bytes']);

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

    if ($method === 'OPTIONS') {
        http_response_code(204);
        header('Allow: GET, POST, PATCH, DELETE, OPTIONS');
        exit;
    }
    if ($method === 'GET' && $path === '/api/health') {
        $pdo->query('SELECT 1')->fetchColumn();
        jsonResponse(['status' => 'ok', 'database' => 'mysql']);
    }
    if ($method === 'POST' && $path === '/api/osm-diff') jsonResponse($diffs->preview(readJsonBody()));
    if ($method === 'POST' && $path === '/api/projects') jsonResponse($projects->create(readJsonBody()), 201);
    if ($method === 'DELETE' && $path === '/api/edit-session') { $sessions->clear(); jsonResponse(['status' => 'ok']); }

    if (preg_match('#^/api/projects/([A-Za-z0-9_-]+)$#', $path, $m)) {
        $publicId = $m[1];
        if ($method === 'GET') {
            $editor = ($_GET['editor'] ?? '') === '1';
            if ($editor) $sessions->requireProject($publicId);
            $project = $projects->get($publicId, $editor);
            if ($project === null) throw new RuntimeException('Project not found');
            jsonResponse($project);
        }
        if ($method === 'PATCH') {
            $editorProject = $sessions->requireProject($publicId);
            $projects->update((int)$editorProject['id'], readJsonBody());
            jsonResponse(['status' => 'ok', 'project' => $projects->get($publicId, true)]);
        }
    }
    if ($method === 'POST' && preg_match('#^/api/projects/([A-Za-z0-9_-]+)/edit-session$#', $path, $m)) {
        $body = readJsonBody();
        $sessions->establish($m[1], requireString($body, 'token', 256));
        jsonResponse(['status' => 'ok']);
    }
    if ($method === 'POST' && preg_match('#^/api/projects/([A-Za-z0-9_-]+)/photos$#', $path, $m)) {
        $editorProject = $sessions->requireProject($m[1]);
        jsonResponse($photos->create((int)$editorProject['id'], $_POST, $_FILES), 201);
    }
    if (preg_match('#^/api/projects/([A-Za-z0-9_-]+)/photos/(\d+)$#', $path, $m)) {
        $editorProject = $sessions->requireProject($m[1]);
        $photoId = (int)$m[2];
        if ($method === 'PATCH') { $photos->update((int)$editorProject['id'], $photoId, readJsonBody()); jsonResponse(['status' => 'ok']); }
        if ($method === 'DELETE') { $photos->delete((int)$editorProject['id'], $photoId); jsonResponse(['status' => 'ok']); }
    }
    if ($method === 'GET' && preg_match('#^/media/([A-Za-z0-9_-]+)/(\d+)/(image|thumb)$#', $path, $m)) {
        $photos->serve($m[1], (int)$m[2], $m[3]);
    }

    $publicDir = realpath(__DIR__ . '/frontend/public');
    if ($method === 'GET' && $publicDir !== false) {
        if ($path === '/' || preg_match('#^/(view|edit)/[A-Za-z0-9_-]+$#', $path)) serveFile($publicDir . '/index.html');
        $candidate = realpath($publicDir . '/' . ltrim($path, '/'));
        if ($candidate !== false && str_starts_with($candidate, $publicDir . DIRECTORY_SEPARATOR) && is_file($candidate)) serveFile($candidate);
    }
    jsonResponse(['error' => 'Not found'], 404);
} catch (InvalidArgumentException $e) {
    jsonResponse(['error' => $e->getMessage()], 422);
} catch (PDOException $e) {
    error_log('Place Field Notes database error: ' . $e->getMessage());
    jsonResponse(['error' => 'Database error'], 500);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $status = 400;
    if (str_contains($message, 'session') || str_contains(strtolower($message), 'token')) $status = 401;
    if (str_contains(strtolower($message), 'not found')) $status = 404;
    if (str_starts_with($message, 'Overpass')) $status = 502;
    jsonResponse(['error' => $message], $status);
} catch (Throwable $e) {
    error_log('Place Field Notes unexpected error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}

function serveFile(string $path): never
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = ['html'=>'text/html; charset=UTF-8','js'=>'text/javascript; charset=UTF-8','css'=>'text/css; charset=UTF-8','json'=>'application/json; charset=UTF-8','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','svg'=>'image/svg+xml'];
    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    if ($extension === 'html') header('Cache-Control: no-cache');
    readfile($path);
    exit;
}
