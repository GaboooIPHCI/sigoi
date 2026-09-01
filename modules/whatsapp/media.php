<?php
require_once __DIR__ . '/_inbox_helpers.php';
auth_require_whatsapp_permission('bandeja_ver');
auth_require_whatsapp_channel('whatsapp');

try {
    global $pdo;
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        exit('Archivo no encontrado.');
    }

    $stmt = $pdo->prepare("SELECT media_local_path, media_url, media_mime, media_filename
        FROM whatsapp_mensajes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        exit('Archivo no encontrado.');
    }

    $mime = trim((string)($row['media_mime'] ?? '')) ?: 'application/octet-stream';
    $filename = trim((string)($row['media_filename'] ?? '')) ?: ('archivo-' . $id);
    $safeFilename = str_replace(["\r", "\n", '"'], '', $filename);

    $local = wa_inbox_storage_absolute((string)($row['media_local_path'] ?? ''));
    if ($local && is_file($local)) {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($local));
        header('Cache-Control: private, max-age=3600');
        $inline = strpos($mime, 'image/') === 0 || strpos($mime, 'audio/') === 0 || strpos($mime, 'video/') === 0 || $mime === 'application/pdf';
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeFilename . '"');
        readfile($local);
        exit;
    }

    $url = trim((string)($row['media_url'] ?? ''));
    if ($url === '' || !function_exists('curl_init')) {
        http_response_code(404);
        exit('El archivo ya no está disponible.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . WHATSAPP_YCLOUD_API_KEY,
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $remoteType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($raw === false || $code < 200 || $code >= 300) {
        http_response_code(404);
        exit('El archivo ya no está disponible.');
    }

    if ($remoteType !== '') {
        $mime = trim(explode(';', $remoteType)[0]);
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen((string)$raw));
    header('Cache-Control: private, max-age=900');
    $inline = strpos($mime, 'image/') === 0 || strpos($mime, 'audio/') === 0 || strpos($mime, 'video/') === 0 || $mime === 'application/pdf';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeFilename . '"');
    echo $raw;
} catch (Throwable $e) {
    error_log('WhatsApp media: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo abrir el archivo.');
}
