<?php
/**
 * FILE PURPOSE: Compatibility redirect from the old standalone batch list to the unified Reservations batch view.
 * DEBUGGING: If batch list navigation changes, update the destination query here.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$query = ['view' => 'batches'];
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $query['search'] = $search;
}
redirect('reservations.php?' . http_build_query($query));
