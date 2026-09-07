<?php
/**
 * Compatibility redirect: historical entry is now integrated into the normal
 * Single Reservation and Batch Reservation creation workflows.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$batchId = max(0, (int)($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0));
flash('info', 'Historical entry is now built into the normal reservation forms. Past dates use the same pricing, add-ons, and payment workflow.');
redirect($batchId > 0 ? 'batch-reservation-create.php?batch_id=' . $batchId : 'reservation-create.php');
