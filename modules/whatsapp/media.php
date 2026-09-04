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
        header('X-Content-Type-Options: nosniff');
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

    /*
     * RC6: el archivo remoto se descarga a un temporal privado en disco.
     * Antes CURLOPT_RETURNTRANSFER cargaba todo el archivo en memoria PHP.
     */
    $tmpDir = dirname(__DIR__, 2) . '/storage/whatsapp/tmp';
    if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0750, true) && !is_dir($tmpDir)) {
        http_response_code(500);
        exit('No se pudo preparar el archivo.');
    }
    $tmp = @tempnam($tmpDir, 'wa_media_');
    if (!is_string($tmp) || $tmp === '') {
        http_response_code(500);
        exit('No se pudo preparar el archivo.');
    }
    register_shutdown_function(static function () use ($tmp): void {
        if (is_file($tmp)) @unlink($tmp);
    });

    $fp = @fopen($tmp, 'wb');
    if (!$fp) {
        @unlink($tmp);
        http_response_code(500);
        exit('No se pudo preparar el archivo.');
    }

    $maxBytes = 50 * 1024 * 1024;
    $tooLarge = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . WHATSAPP_YCLOUD_API_KEY,
        ],
        CURLOPT_NOPROGRESS => false,
        CURLOPT_XFERINFOFUNCTION => static function (
            $resource,
            float $downloadSize,
            float $downloaded,
            float $uploadSize,
            float $uploaded
        ) use ($maxBytes, &$tooLarge): int {
            if ($downloadSize > $maxBytes || $downloaded > $maxBytes) {
                $tooLarge = true;
                return 1;
            }
            return 0;
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $remoteType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    fclose($fp);

    $size = is_file($tmp) ? (int)filesize($tmp) : 0;
    if (!$ok || $tooLarge || $code < 200 || $code >= 300 || $size <= 0 || $size > $maxBytes) {
        @unlink($tmp);
        http_response_code($tooLarge ? 413 : 404);
        exit($tooLarge ? 'El archivo supera el límite seguro de visualización.' : 'El archivo ya no está disponible.');
    }

    if ($remoteType !== '') {
        $mime = trim(explode(';', $remoteType)[0]);
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Cache-Control: private, max-age=900');
    header('X-Content-Type-Options: nosniff');
    $inline = strpos($mime, 'image/') === 0 || strpos($mime, 'audio/') === 0 || strpos($mime, 'video/') === 0 || $mime === 'application/pdf';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeFilename . '"');
    readfile($tmp);
    @unlink($tmp);
} catch (Throwable $e) {
    error_log('WhatsApp media: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo abrir el archivo.');
}
