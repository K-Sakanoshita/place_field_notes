<?php
require 'src/Database.php';
require 'src/Utils.php';
require 'src/ProjectController.php';

$db = new Database('test.sqlite');
$pc = new ProjectController($db);
$project = $pc->create([
    'title' => 'Test',
    'description' => 'Desc',
    'bbox' => [0,0,1,1],
    'start_at' => '2023-01-01T00:00:00',
    'end_at' => '2023-01-01T01:00:00',
    'timezone' => 'UTC',
    'base_map' => 'map'
]);
print_r($project);
?>