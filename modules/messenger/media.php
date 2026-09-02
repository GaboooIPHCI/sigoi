<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

auth_require_whatsapp_permission('bandeja_ver');

try {
    global $pdo;

    messenger_ensure_schema($pdo);
    messenger_require_channel_permission($pdo);

    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(404);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            media_path,
            media_url,
            media_mime,
            media_filename
        FROM messenger_mensajes
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        exit;
    }

    $path = messenger_storage_absolute(
        (string)($row['media_path'] ?? '')
    );

    if ($path && is_file($path)) {
        $mime = trim((string)($row['media_mime'] ?? ''))
            ?: 'application/octet-stream';

        $filename = messenger_safe_filename(
            (string)($row['media_filename'] ?? ''),
            'messenger'
        );

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        header(
            'Content-Disposition: inline; filename="'
            . addcslashes($filename, '"\\')
            . '"'
        );
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');

        readfile($path);
        exit;
    }

    $remote = trim((string)($row['media_url'] ?? ''));

    if (
        $remote !== ''
        && preg_match('#^https://#i', $remote)
    ) {
        header('Location: ' . $remote, true, 302);
        exit;
    }

    http_response_code(404);

} catch (Throwable $e) {
    error_log('Messenger media: ' . $e->getMessage());
    http_response_code(500);
}
