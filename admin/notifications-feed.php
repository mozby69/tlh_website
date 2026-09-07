<?php
/**
 * FILE PURPOSE: JSON feed used by the admin notification bell for unread count and recent alerts.
 * DEBUGGING: If the bell stops refreshing, inspect this response and the notification refresh block in assets/js/app.js.
 */
require_once __DIR__ . '/../includes/functions.php';
admin_required();
header('Content-Type: application/json; charset=utf-8');
$items = admin_recent_notifications(8);
$out = [];
foreach ($items as $item) {
    $out[] = [
        'id' => (int)$item['id'],
        'title' => (string)$item['title'],
        'message' => (string)($item['message'] ?? ''),
        'is_read' => (int)$item['is_read'] === 1,
        'created_at' => date('M j, g:i A', strtotime((string)$item['created_at'])),
        'url' => 'notification-open.php?id=' . (int)$item['id'],
    ];
}
echo json_encode(['unread' => admin_notification_unread_count(), 'items' => $out], JSON_UNESCAPED_SLASHES);
