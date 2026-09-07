<?php
/**
 * FILE PURPOSE: Serves managed images stored in the database fallback when the web server cannot write upload files.
 * DEBUGGING: Only return validated image records/MIME types. Do not turn this into a generic file-serving endpoint.
 */
require_once __DIR__ . '/config/database.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(404);
    exit;
}

try {
    $stmt = db()->prepare('SELECT mime_type, size_bytes, created_at FROM media_assets WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $asset = $stmt->fetch();
    if (!$asset) {
        http_response_code(404);
        exit;
    }

    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mime = strtolower((string)$asset['mime_type']);
    if (!in_array($mime, $allowedMimeTypes, true)) {
        http_response_code(415);
        exit;
    }

    $etag = '"tlh-media-' . $id . '-' . (int)$asset['size_bytes'] . '"';
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (int)$asset['size_bytes']);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');

    $chunks = db()->prepare('SELECT chunk_data FROM media_asset_chunks WHERE asset_id=? ORDER BY chunk_no');
    $chunks->execute([$id]);
    while ($chunk = $chunks->fetchColumn()) {
        echo $chunk;
        flush();
    }
} catch (Throwable $e) {
    http_response_code(404);
}
