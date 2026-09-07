<?php
/**
 * FILE PURPOSE: Marks an admin notification as read and redirects to the related reservation.
 * DEBUGGING: Reservation-linked notification helpers are in includes/functions.php.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    redirect('notifications.php');
}

try {
    $stmt = db()->prepare('SELECT reservation_id FROM admin_notifications WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $reservationId = $stmt->fetchColumn();
    if ($reservationId !== false && $reservationId !== null && (int)$reservationId > 0) {
        // reservation-view.php marks all notifications for the reservation read
        // after authorization, so this GET endpoint remains navigation-only.
        redirect('reservation-view.php?id=' . (int)$reservationId);
    }
} catch (Throwable $ignored) {
}

flash('danger', 'The selected notification is no longer available.');
redirect('notifications.php');
