<?php

declare(strict_types=1);

require_once __DIR__ . '/_media_helpers.php';

ig_outbox_cleanup();
$share = ig_outbox_read_share((string)($_GET['t'] ?? ''));
if (!$share) {
    http_response_code(404);
    exit;
}

$path = (string)$share['absolute'];
$mime = trim((string)($share['mime'] ?? '')) ?: 'application/octet-stream';
$filename = ig_media_safe_filename((string)($share['filename'] ?? ''), 'instagram');

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . addcslashes($filename, '"\\') . '"');
header('Cache-Control: private, max-age=300');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
readfile($path);
