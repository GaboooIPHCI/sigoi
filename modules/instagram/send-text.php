<?php

declare(strict_types=1);

require_once __DIR__ . '/_inbox_helpers.php';
auth_require_whatsapp_permission('bandeja_responder');
auth_require_whatsapp_channel('instagram');
auth_validate_csrf();

try {
    global $pdo;
    instagram_ensure_schema($pdo);

    $data = instagram_request();
    $conversationId = (int)($data['conversation_id'] ?? 0);
    $body = instagram_clean_text($data['body'] ?? '', 1000);

    if ($conversationId <= 0 || $body === '') {
        instagram_json(false, 'Escribe un mensaje antes de enviarlo.', [], 422);
    }

    $conversation = ig_inbox_conversation_row($pdo, $conversationId);
    if (!$conversation) instagram_json(false, 'La conversación ya no existe.', [], 404);

    if (!ig_inbox_send_enabled()) {
        instagram_json(false, 'El envío desde S.I.G.O.I. todavía no está activado para Instagram. La recepción ya está preparada para pruebas.', [], 409);
    }

    $window = ig_inbox_send_window($pdo, $conversationId);
    if (empty($window['active'])) {
        instagram_json(false, 'La ventana estándar de mensajería de Instagram ya terminó para esta conversación.', [], 409);
    }

    $send = ig_meta_send_text((string)$conversation['igsid'], $body);
    if (empty($send['ok'])) {
        instagram_json(false, (string)($send['error'] ?? 'No se pudo enviar el mensaje por Instagram.'), [], 502);
    }

    $mid = trim((string)($send['message_id'] ?? ''));
    if ($mid === '') {
        $mid = 'ig-sigoi:' . bin2hex(random_bytes(12));
    }
    $now = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO instagram_mensajes
            (conversacion_id, mid, direccion, origen, usuario_id, tipo, contenido, estado_envio, creado_en)
            VALUES (:cid, :mid, 'saliente', 'sigoi', :user_id, 'texto', :content, 'enviado', :created_at)");
        $stmt->execute([
            ':cid' => $conversationId,
            ':mid' => $mid,
            ':user_id' => auth_user_id(),
            ':content' => $body,
            ':created_at' => $now,
        ]);

        $pdo->prepare("UPDATE instagram_conversaciones SET
            estado = 'abierta', requiere_humano = 0, resuelto_en = NULL,
            ultimo_mensaje_en = :last_at,
            ultimo_mensaje_direccion = 'saliente',
            ultimo_mensaje_tipo = 'texto',
            ultimo_mensaje_preview = :preview,
            ultimo_mensaje_origen = 'sigoi',
            ultimo_mensaje_id = :mid
            WHERE id = :id")
            ->execute([
                ':last_at' => $now,
                ':preview' => ig_preview($body, 'texto'),
                ':mid' => $mid,
                ':id' => $conversationId,
            ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    auth_audit(
        $pdo,
        'instagram_mensaje_manual',
        auth_user_id(),
        auth_username(),
        json_encode(['conversacion_id' => $conversationId, 'mid' => $mid], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
    );

    instagram_json(true, 'Mensaje enviado por Instagram.', ['message_id' => $mid]);
} catch (Throwable $e) {
    error_log('Instagram send-text: ' . $e->getMessage());
    instagram_json(false, 'No se pudo enviar el mensaje por Instagram.', [], 500);
}
