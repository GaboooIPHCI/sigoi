<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

auth_require_whatsapp_permission('bandeja_responder');
auth_validate_csrf();

try {
    global $pdo;

    messenger_ensure_schema($pdo);
    messenger_require_channel_permission($pdo);

    $data = messenger_request();

    $conversationId = (int)($data['conversation_id'] ?? 0);
    $body = messenger_clean_text($data['body'] ?? '', 2000);

    if ($conversationId <= 0) {
        messenger_json(false, 'Conversación inválida.', [], 422);
    }

    if ($body === '') {
        messenger_json(false, 'Escribe un mensaje.', [], 422);
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

    $send = messenger_send_text_api(
        (string)$conversation['psid'],
        $body
    );

    if (!($send['ok'] ?? false)) {
        messenger_json(
            false,
            (string)($send['error'] ?? 'No se pudo enviar el mensaje.'),
            [],
            502
        );
    }

    $mid = trim((string)($send['message_id'] ?? ''));

    if ($mid === '') {
        $mid = 'sigoi_' . hash(
            'sha256',
            $conversationId
            . '|'
            . microtime(true)
            . '|'
            . random_int(1, PHP_INT_MAX)
        );
    }

    $createdAt = (
        new DateTimeImmutable('now', new DateTimeZone('America/Lima'))
    )->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO messenger_mensajes (
            conversacion_id,
            mid,
            direccion,
            origen,
            usuario_id,
            tipo,
            contenido,
            estado_envio,
            creado_en
        )
        VALUES (
            :conversation_id,
            :mid,
            'saliente',
            'sigoi',
            :usuario_id,
            'text',
            :body,
            'sent',
            :created_at
        )
        ON DUPLICATE KEY UPDATE
            estado_envio = VALUES(estado_envio),
            contenido = VALUES(contenido)
    ");

    $stmt->execute([
        ':conversation_id' => $conversationId,
        ':mid' => $mid,
        ':usuario_id' => auth_user_id(),
        ':body' => $body,
        ':created_at' => $createdAt,
    ]);

    $pdo->prepare("
        UPDATE messenger_conversaciones
        SET
            ultimo_mensaje_en = :created_at,
            ultimo_mensaje_direccion = 'saliente',
            ultimo_mensaje_tipo = 'text',
            ultimo_mensaje_preview = :preview,
            ultimo_mensaje_origen = 'sigoi',
            ultimo_mensaje_id = :mid
        WHERE id = :id
    ")->execute([
        ':created_at' => $createdAt,
        ':preview' => messenger_clean_text($body, 300),
        ':mid' => $mid,
        ':id' => $conversationId,
    ]);

    auth_audit(
        $pdo,
        'messenger_mensaje_manual',
        auth_user_id(),
        auth_username(),
        json_encode([
            'conversacion_id' => $conversationId,
            'mid' => $mid,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
    );

    messenger_json(true, 'Mensaje enviado.', [
        'message_id' => $mid,
    ]);

} catch (Throwable $e) {
    error_log('Messenger send-text: ' . $e->getMessage());

    messenger_json(
        false,
        'No se pudo enviar el mensaje de Messenger.',
        [],
        500
    );
}
