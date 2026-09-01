<?php
require_once __DIR__ . '/_inbox_helpers.php';
auth_require_whatsapp_permission('bandeja_responder');
auth_require_whatsapp_channel('whatsapp');
auth_validate_csrf();

try {
    global $pdo;

    $conversationId = (int)($_POST['conversation_id'] ?? 0);
    $caption = whatsapp_clean_text($_POST['caption'] ?? '', 1024);

    if ($conversationId <= 0) {
        whatsapp_json(false, 'Conversación inválida.', [], 422);
    }
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        whatsapp_json(false, 'Selecciona un archivo para enviar.', [], 422);
    }

    $conversation = wa_inbox_conversation_row($pdo, $conversationId);
    if (!$conversation) {
        whatsapp_json(false, 'La conversación ya no existe.', [], 404);
    }

    $window = wa_inbox_send_window($pdo, $conversationId);
    if (empty($window['active'])) {
        whatsapp_json(false, 'La ventana de atención de 24 horas ya terminó. Para iniciar nuevamente la conversación se requiere una plantilla aprobada.', [], 409);
    }

    $file = $_FILES['file'];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'El archivo supera el límite configurado en PHP.',
            UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite permitido.',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente. Inténtalo otra vez.',
            UPLOAD_ERR_NO_FILE => 'No se recibió ningún archivo.',
        ];
        whatsapp_json(false, $messages[$error] ?? 'No se pudo recibir el archivo.', [], 422);
    }

    $tmp = (string)$file['tmp_name'];
    $filename = whatsapp_clean_text($file['name'] ?? 'archivo', 240);
    $size = (int)($file['size'] ?? 0);

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $tmp);
            if (is_string($detected)) {
                $mime = $detected;
            }
            finfo_close($finfo);
        }
    }
    if ($mime === '') {
        $mime = whatsapp_clean_text($file['type'] ?? 'application/octet-stream', 120);
    }

    $stored = wa_inbox_store_uploaded_file($tmp, $filename, $mime, $size);

    $upload = wa_auto_upload_media(
        (string)$stored['absolute_path'],
        (string)$stored['filename'],
        (string)$stored['mime']
    );
    if (!($upload['ok'] ?? false)) {
        whatsapp_json(false, (string)($upload['error'] ?? 'No se pudo subir el archivo a YCloud.'), [], 502);
    }

    $externalId = wa_auto_external_id('media', $conversationId);
    $send = wa_auto_send_media(
        (string)$conversation['telefono'],
        (string)$stored['type'],
        (string)$upload['media_id'],
        $caption,
        (string)$stored['filename'],
        $externalId
    );

    $mediaMeta = [
        'type' => $stored['type'],
        'media_id' => $upload['media_id'],
        'local_path' => $stored['local_path'],
        'mime' => $stored['mime'],
        'filename' => $stored['filename'],
        'size' => $stored['size'],
    ];

    $messageId = wa_auto_store_outgoing(
        $pdo,
        $conversationId,
        $caption,
        null,
        $send,
        'sigoi',
        auth_user_id(),
        $mediaMeta,
        $externalId
    );

    if (!($send['ok'] ?? false)) {
        whatsapp_json(false, (string)($send['error'] ?? 'No se pudo enviar el archivo.'), [
            'message_id' => $messageId,
        ], 502);
    }

    auth_audit(
        $pdo,
        'whatsapp_archivo_manual',
        auth_user_id(),
        auth_username(),
        json_encode([
            'conversacion_id' => $conversationId,
            'mensaje_id' => $messageId,
            'tipo' => $stored['type'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
    );

    whatsapp_json(true, 'Archivo enviado.', ['message_id' => $messageId]);
} catch (Throwable $e) {
    error_log('WhatsApp send-media: ' . $e->getMessage());
    whatsapp_json(false, $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo enviar el archivo.', [], 500);
}
