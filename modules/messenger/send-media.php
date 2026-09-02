<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

auth_require_whatsapp_permission('bandeja_responder');
auth_validate_csrf();

try {
    global $pdo;

    messenger_ensure_schema($pdo);
    messenger_require_channel_permission($pdo);

    $conversationId = (int)($_POST['conversation_id'] ?? 0);
    $caption = messenger_clean_text($_POST['caption'] ?? '', 2000);

    if ($conversationId <= 0) {
        messenger_json(false, 'Conversación inválida.', [], 422);
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        messenger_json(false, 'Selecciona un archivo.', [], 422);
    }

    $conversation = messenger_conversation_row(
        $pdo,
        $conversationId
    );

    if (!$conversation) {
        messenger_json(
            false,
            'La conversación ya no existe.',
            [],
            404
        );
    }

    $window = messenger_send_window($pdo, $conversationId);

    if (empty($window['active'])) {
        messenger_json(
            false,
            'La ventana de mensajería de 24 horas de Messenger ya terminó.',
            [],
            409
        );
    }

    if (!messenger_send_enabled()) {
        messenger_json(
            false,
            'El envío de Messenger todavía está desactivado.',
            [],
            409
        );
    }

    if (messenger_public_base_url() === '') {
        messenger_json(
            false,
            'Falta configurar MESSENGER_PUBLIC_BASE_URL.',
            [],
            500
        );
    }

    $file = $_FILES['file'];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        messenger_json(
            false,
            'No se pudo recibir el archivo.',
            [],
            422
        );
    }

    $tmp = (string)$file['tmp_name'];
    $filename = messenger_safe_filename(
        (string)($file['name'] ?? 'archivo'),
        'archivo'
    );
    $size = (int)($file['size'] ?? 0);

    $mime = '';

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo) {
            $detected = finfo_file($finfo, $tmp);

            if (is_string($detected)) {
                $mime = trim($detected);
            }

            finfo_close($finfo);
        }
    }

    if ($mime === '') {
        $mime = messenger_clean_text(
            $file['type'] ?? 'application/octet-stream',
            160
        );
    }

    $type = messenger_detect_media_type($mime, $filename);

    if ($type === null) {
        messenger_json(
            false,
            'Ese tipo de archivo no está permitido.',
            [],
            422
        );
    }

    $limit = messenger_media_limit($type);

    if ($size <= 0 || ($limit > 0 && $size > $limit)) {
        messenger_json(
            false,
            'El archivo supera el límite permitido para Messenger.',
            [],
            422
        );
    }

    $dir = messenger_ensure_storage_dir('sent');
    $ext = messenger_extension_from_mime($mime, $filename);

    try {
        $random = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $random = sha1(uniqid('', true));
    }

    $target = $dir
        . '/'
        . date('Ymd_His')
        . '_'
        . $random
        . '.'
        . $ext;

    if (!@move_uploaded_file($tmp, $target)) {
        if (!@copy($tmp, $target)) {
            messenger_json(
                false,
                'No se pudo guardar el archivo en el servidor.',
                [],
                500
            );
        }
    }

    @chmod($target, 0640);

    $relativePath = messenger_storage_relative($target);

    try {
        $publicToken = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $publicToken = hash(
            'sha256',
            $relativePath . microtime(true) . uniqid('', true)
        );
    }

    $expires = (
        new DateTimeImmutable('now', new DateTimeZone('America/Lima'))
    )->modify('+24 hours');

    $pdo->prepare("
        INSERT INTO messenger_outbox_media (
            token,
            media_path,
            mime,
            filename,
            expira_en
        )
        VALUES (
            :token,
            :media_path,
            :mime,
            :filename,
            :expira_en
        )
    ")->execute([
        ':token' => $publicToken,
        ':media_path' => $relativePath,
        ':mime' => $mime,
        ':filename' => $filename,
        ':expira_en' => $expires->format('Y-m-d H:i:s'),
    ]);

    $publicUrl = messenger_public_base_url()
        . '/modules/messenger/outbox-media.php?t='
        . rawurlencode($publicToken);

    $send = messenger_send_media_api(
        (string)$conversation['psid'],
        $type,
        $publicUrl
    );

    if (!($send['ok'] ?? false)) {
        messenger_json(
            false,
            (string)($send['error'] ?? 'No se pudo enviar el archivo.'),
            [],
            502
        );
    }

    $mid = trim((string)($send['message_id'] ?? ''));

    if ($mid === '') {
        $mid = 'sigoi_media_' . hash(
            'sha256',
            $conversationId
            . '|'
            . microtime(true)
            . '|'
            . $publicToken
        );
    }

    $createdAt = (
        new DateTimeImmutable('now', new DateTimeZone('America/Lima'))
    )->format('Y-m-d H:i:s');

    $pdo->prepare("
        INSERT INTO messenger_mensajes (
            conversacion_id,
            mid,
            direccion,
            origen,
            usuario_id,
            tipo,
            contenido,
            media_path,
            media_mime,
            media_filename,
            media_size,
            estado_envio,
            creado_en
        )
        VALUES (
            :conversation_id,
            :mid,
            'saliente',
            'sigoi',
            :usuario_id,
            :type,
            :caption,
            :media_path,
            :mime,
            :filename,
            :size,
            'sent',
            :created_at
        )
        ON DUPLICATE KEY UPDATE
            estado_envio = VALUES(estado_envio)
    ")->execute([
        ':conversation_id' => $conversationId,
        ':mid' => $mid,
        ':usuario_id' => auth_user_id(),
        ':type' => $type,
        ':caption' => $caption !== '' ? $caption : null,
        ':media_path' => $relativePath,
        ':mime' => $mime,
        ':filename' => $filename,
        ':size' => $size,
        ':created_at' => $createdAt,
    ]);

    $preview = $caption !== ''
        ? $caption
        : '[' . ucfirst($type) . ']';

    $pdo->prepare("
        UPDATE messenger_conversaciones
        SET
            ultimo_mensaje_en = :created_at,
            ultimo_mensaje_direccion = 'saliente',
            ultimo_mensaje_tipo = :type,
            ultimo_mensaje_preview = :preview,
            ultimo_mensaje_origen = 'sigoi',
            ultimo_mensaje_id = :mid
        WHERE id = :id
    ")->execute([
        ':created_at' => $createdAt,
        ':type' => $type,
        ':preview' => messenger_clean_text($preview, 300),
        ':mid' => $mid,
        ':id' => $conversationId,
    ]);

    auth_audit(
        $pdo,
        'messenger_archivo_manual',
        auth_user_id(),
        auth_username(),
        json_encode([
            'conversacion_id' => $conversationId,
            'mid' => $mid,
            'tipo' => $type,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
    );

    messenger_json(true, 'Archivo enviado.', [
        'message_id' => $mid,
    ]);

} catch (Throwable $e) {
    error_log('Messenger send-media: ' . $e->getMessage());

    messenger_json(
        false,
        'No se pudo enviar el archivo por Messenger.',
        [],
        500
    );
}
