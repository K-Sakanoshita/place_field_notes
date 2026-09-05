<?php
require 'src/Database.php';
$db = new Database('test.sqlite');
$stmt = $db->prepare("INSERT INTO projects (public_id,edit_token_hash,title,description,bbox,start_at,end_at,timezone,base_map,created_at,updated_at) VALUES (:pid,:hash,:title,:desc,:bbox,:start_at,:end_at,:timezone,:base_map,:created_at,:updated_at)");
$stmt->execute([
    ':pid'=>'pid1',
    ':hash'=>'hash1',
    ':title'=>'Title1',
    ':desc'=>'Desc1',
    ':bbox'=>'[0,0,1,1]',
    ':start_at'=>'2023-01-01',
    ':end_at'=>'2023-01-02',
    ':timezone'=>'UTC',
    ':base_map'=>'map',
    ':created_at'=>'2023-01-01T00:00:00Z',
    ':updated_at'=>'2023-01-01T00:00:00Z'
]);
$stmt = $db->prepare('SELECT * FROM projects');
$stmt->execute();
print_r($stmt->fetch());
?>