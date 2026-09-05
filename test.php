<?php
// Simulate request to create project
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/projects';
// Set body
$body = json_encode([
    'title' => 'Test Project',
    'bbox' => [139.700, 35.680, 139.710, 35.690],
    'start_at' => '2026-09-20T13:00:00',
    'end_at' => '2026-09-20T15:00:00',
    'timezone' => 'Asia/Tokyo',
    'base_map' => '2026-09',
]);
file_put_contents('php://input', $body);
// Include index.php
require_once __DIR__.'/index.php';
?>
