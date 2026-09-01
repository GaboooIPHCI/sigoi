<?php
require_once __DIR__ . '/_inbox_helpers.php';
auth_require_whatsapp_permission('bandeja_responder');
auth_require_whatsapp_channel('whatsapp');
auth_validate_csrf();

try {
    global $pdo;
    $data = whatsapp_request();
    $conversationId = (int)($data['conversation_id'] ?? 0);
    $body = whatsapp_clean_text($data['body'] ?? '', 4000);

    if ($conversationId <= 0 || $body === '') {
        whatsapp_json(false, 'Escribe un mensaje antes de enviarlo.', [], 422);
    }

    $conversation = wa_inbox_conversation_row($pdo, $conversationId);
    if (!$conversation) {
        whatsapp_json(false, 'La conversación ya no existe.', [], 404);
    }

    $window = wa_inbox_send_window($pdo, $conversationId);
    if (empty($window['active'])) {
        whatsapp_json(false, 'La ventana de atención de 24 horas ya terminó. Para iniciar nuevamente la conversación se requiere una plantilla aprobada.', [], 409);
    }

    $externalId = wa_auto_external_id('manual', $conversationId);
    $send = wa_auto_send_text((string)$conversation['telefono'], $body, $externalId);

    $messageId = wa_auto_store_outgoing(
        $pdo,
        $conversationId,
        $body,
        null,
        $send,
        'sigoi',
        auth_user_id(),
        [],
        $externalId
    );

    if (!($send['ok'] ?? false)) {
        whatsapp_json(false, (string)($send['error'] ?? 'No se pudo enviar el mensaje.'), [
            'message_id' => $messageId,
        ], 502);
    }

    auth_audit(
        $pdo,
        'whatsapp_mensaje_manual',
        auth_user_id(),
        auth_username(),
        json_encode([
            'conversacion_id' => $conversationId,
            'mensaje_id' => $messageId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
    );

    whatsapp_json(true, 'Mensaje enviado.', ['message_id' => $messageId]);
} catch (Throwable $e) {
    error_log('WhatsApp send-text: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo enviar el mensaje.', [], 500);
}
