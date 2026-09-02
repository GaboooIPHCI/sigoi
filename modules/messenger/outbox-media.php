<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/messenger_schema.php';

try {
    global $pdo;

    messenger_ensure_schema($pdo);

    $token = trim((string)($_GET['t'] ?? ''));

    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        http_response_code(404);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            media_path,
            mime,
            filename,
            expira_en
        FROM messenger_outbox_media
        WHERE token = :token
          AND expira_en >= NOW()
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        exit;
    }

    $relative = str_replace(
        '\\',
        '/',
        trim((string)$row['media_path'])
    );

    if (
        $relative === ''
        || strpos($relative, '..') !== false
        || strpos($relative, 'storage/messenger/') !== 0
    ) {
        http_response_code(404);
        exit;
    }

    $path = dirname(__DIR__, 2) . '/' . $relative;

    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }

    $mime = trim((string)($row['mime'] ?? ''))
        ?: 'application/octet-stream';

    $filename = trim((string)($row['filename'] ?? ''))
        ?: 'messenger';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: inline; filename="'
        . addcslashes($filename, '"\\')
        . '"');
    header('Cache-Control: public, max-age=300');
    header('X-Content-Type-Options: nosniff');

    readfile($path);

} catch (Throwable $e) {
    error_log('Messenger outbox-media: ' . $e->getMessage());
    http_response_code(404);
}
