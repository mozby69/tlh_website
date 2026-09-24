<?php
require_once __DIR__ . '/../includes/functions.php'; admin_required(); if(!is_admin()){redirect('index.php');} redirect('rentals.php');
