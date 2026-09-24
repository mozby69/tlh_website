<?php
require_once __DIR__ . '/../includes/functions.php';
admin_required(); if(!is_admin()){redirect('index.php');}
$legacy=(int)($_GET['id']??$_POST['id']??$_POST['rental_id']??0);
if($legacy>0){ $stmt=db()->prepare('SELECT id FROM rentals WHERE legacy_food_stall_rental_id=? LIMIT 1'); $stmt->execute([$legacy]); $newId=(int)$stmt->fetchColumn(); if($newId>0) redirect('rental-print.php?id='.$newId); }
redirect('rental-print.php');
