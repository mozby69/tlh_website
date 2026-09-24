<?php
require_once __DIR__ . '/../includes/functions.php'; admin_required(); if(!is_admin()){redirect('index.php');} $q=$_SERVER['QUERY_STRING']??''; redirect('rental-calendar.php'.($q?'?'.$q:''));
