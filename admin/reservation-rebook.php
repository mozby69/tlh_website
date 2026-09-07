<?php
/**
 * FILE PURPOSE: Creates a new reservation form prefilled from an older reservation.
 * DEBUGGING: Rebooking creates a new booking/reference; it must not reopen or mutate the original record.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
$id = (int)($_GET['id'] ?? $_POST['source_reservation_id'] ?? $_POST['id'] ?? 0);
redirect('reservation-reschedule.php' . ($id > 0 ? '?id=' . $id : ''));
