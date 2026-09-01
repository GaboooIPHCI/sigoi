<?php

declare(strict_types=1);

require_once __DIR__ . '/_inbox_helpers.php';
auth_require_whatsapp_permission('bandeja_responder');
auth_require_whatsapp_channel('instagram');
auth_validate_csrf();

try {
    global $pdo;
    instagram_ensure_schema($pdo);

    $conversationId = (int)($_POST['conversation_id'] ?? 0);
    $caption = instagram_clean_text($_POST['caption'] ?? '', 1000);
    if ($conversationId <= 0) instagram_json(false, 'Conversación inválida.', [], 422);

    $conversation = ig_inbox_conversation_row($pdo, $conversationId);
    if (!$conversation) instagram_json(false, 'La conversación ya no existe.', [], 404);
    if (!ig_inbox_send_enabled()) instagram_json(false, 'El envío de Instagram está desactivado.', [], 409);

    $window = ig_inbox_send_window($pdo, $conversationId);
    if (empty($window['active'])) {
        instagram_json(false, 'La ventana estándar de mensajería de Instagram ya terminó para esta conversación.', [], 409);
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        instagram_json(false, 'Selecciona una imagen, video o audio.', [], 422);
    }

    $file = $_FILES['file'];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) instagram_json(false, 'No se pudo recibir el archivo seleccionado.', [], 422);

    $tmp = (string)($file['tmp_name'] ?? '');
    $originalName = (string)($file['name'] ?? 'instagram');
    $size = (int)($file['size'] ?? 0);
    $maxBytes = 25 * 1024 * 1024;
    if ($size <= 0 || $size > $maxBytes) {
        instagram_json(false, 'El archivo debe pesar como máximo 25 MB.', [], 422);
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }
    if ($mime === '') $mime = trim((string)($file['type'] ?? ''));

    if (strpos($mime, 'image/') === 0) {
        $attachmentType = 'image';
        $dbType = 'imagen';
        $placeholder = '[Imagen]';
    } elseif (strpos($mime, 'video/') === 0) {
        $attachmentType = 'video';
        $dbType = 'video';
        $placeholder = '[Video]';
    } elseif (strpos($mime, 'audio/') === 0) {
        $attachmentType = 'audio';
        $dbType = 'audio';
        $placeholder = '[Audio]';
    } else {
        instagram_json(false, 'Instagram permite enviar desde S.I.G.O.I. imágenes, video o audio. Este tipo de archivo no es compatible.', [], 422);
    }

    $stored = ig_media_store_uploaded_file($tmp, $mime, $originalName);
    $shareToken = ig_outbox_create_share($stored['path'], $stored['mime'], $stored['filename'], 3600);
    $publicUrl = ig_outbox_public_url($shareToken);

    $sendMedia = ig_meta_send_media((string)$conversation['igsid'], $attachmentType, $publicUrl);
    if (empty($sendMedia['ok'])) {
        @unlink((string)$stored['absolute']);
        instagram_json(false, (string)($sendMedia['error'] ?? 'No se pudo enviar el archivo por Instagram.'), [], 502);
    }

    $mediaMid = trim((string)($sendMedia['message_id'] ?? ''));
    if ($mediaMid === '') $mediaMid = 'ig-sigoi-media:' . bin2hex(random_bytes(12));
    $now = date('Y-m-d H:i:s');

    $captionSend = null;
    if ($caption !== '') {
        $captionSend = ig_meta_send_text((string)$conversation['igsid'], $caption);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO instagram_mensajes
            (conversacion_id, mid, direccion, origen, usuario_id, tipo, contenido,
             media_path, media_mime, media_filename, media_size, estado_envio, creado_en)
            VALUES (:cid, :mid, 'saliente', 'sigoi', :user_id, :type, :content,
             :media_path, :media_mime, :media_filename, :media_size, 'enviado', :created_at)");
        $stmt->execute([
            ':cid' => $conversationId,
            ':mid' => $mediaMid,
            ':user_id' => auth_user_id(),
            ':type' => $dbType,
            ':content' => $placeholder,
            ':media_path' => $stored['path'],
            ':media_mime' => $stored['mime'],
            ':media_filename' => $stored['filename'],
            ':media_size' => $stored['size'],
            ':created_at' => $now,
        ]);

        $lastMid = $mediaMid;
        $lastType = $dbType;
        $lastPreview = $placeholder;
        $lastAt = $now;

        if ($caption !== '' && is_array($captionSend) && !empty($captionSend['ok'])) {
            $captionMid = trim((string)($captionSend['message_id'] ?? ''));
            if ($captionMid === '') $captionMid = 'ig-sigoi:' . bin2hex(random_bytes(12));
            $captionAt = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO instagram_mensajes
                (conversacion_id, mid, direccion, origen, usuario_id, tipo, contenido, estado_envio, creado_en)
                VALUES (:cid, :mid, 'saliente', 'sigoi', :user_id, 'texto', :content, 'enviado', :created_at)")
                ->execute([
                    ':cid' => $conversationId,
                    ':mid' => $captionMid,
                    ':user_id' => auth_user_id(),
                    ':content' => $caption,
                    ':created_at' => $captionAt,
                ]);
            $lastMid = $captionMid;
            $lastType = 'texto';
            $lastPreview = ig_preview($caption, 'texto');
            $lastAt = $captionAt;
        }

        $pdo->prepare("UPDATE instagram_conversaciones SET
            estado = 'abierta', requiere_humano = 0, resuelto_en = NULL,
            ultimo_mensaje_en = :last_at,
            ultimo_mensaje_direccion = 'saliente',
            ultimo_mensaje_tipo = :type,
            ultimo_mensaje_preview = :preview,
            ultimo_mensaje_origen = 'sigoi',
            ultimo_mensaje_id = :mid
            WHERE id = :id")
            ->execute([
                ':last_at' => $lastAt,
                ':type' => $lastType,
                ':preview' => $lastPreview,
                ':mid' => $lastMid,
                ':id' => $conversationId,
            ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    auth_audit(
        $pdo,
        'instagram_media_manual',
        auth_user_id(),
        auth_username(),
        json_encode(['conversacion_id' => $conversationId, 'mid' => $mediaMid, 'tipo' => $attachmentType], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
    );

    $captionWarning = $caption !== '' && (!is_array($captionSend) || empty($captionSend['ok']));
    $message = $captionWarning
        ? 'Archivo enviado. El texto adjunto no pudo enviarse; puedes escribirlo nuevamente en el chat.'
        : ($caption !== '' ? 'Archivo y texto enviados por Instagram.' : 'Archivo enviado por Instagram.');

    instagram_json(true, $message, [
        'message_id' => $mediaMid,
        'caption_sent' => !$captionWarning && $caption !== '',
        'warning' => $captionWarning,
    ]);
} catch (Throwable $e) {
    error_log('Instagram send-media: ' . $e->getMessage());
    instagram_json(false, 'No se pudo enviar el archivo por Instagram.', [], 500);
}
