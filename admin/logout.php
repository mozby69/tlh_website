<?php
/**
 * FILE PURPOSE: POST-only CSRF-protected admin logout endpoint.
 * DEBUGGING: Do not convert logout back to a GET action; POST prevents cross-site forced logout requests.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

verify_csrf();
$_SESSION = [];
session_regenerate_id(true);
flash('success', 'You have been signed out.');
redirect('login.php');
