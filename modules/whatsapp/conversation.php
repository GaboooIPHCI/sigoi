<?php
require_once __DIR__ . '/_template_helpers.php';
auth_require_whatsapp_permission('bandeja_ver');
auth_require_whatsapp_channel('whatsapp');

try {
    global $pdo;
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        whatsapp_json(false, 'Conversación inválida.', [], 422);
    }

    $conversation = wa_inbox_conversation_row($pdo, $id);
    if (!$conversation) {
        whatsapp_json(false, 'La conversación ya no existe.', [], 404);
    }

    $stmt = $pdo->prepare("SELECT m.*, u.nombre AS usuario_nombre, r.nombre AS regla_nombre
        FROM whatsapp_mensajes m
        LEFT JOIN usuarios_sistema u ON u.id = m.usuario_id
        LEFT JOIN whatsapp_reglas r ON r.id = m.regla_id
        WHERE m.conversacion_id = :id
        ORDER BY m.creado_en ASC, m.id ASC
        LIMIT 400");
    $stmt->execute([':id' => $id]);
    $messageRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // El historial principal nunca depende de la tabla de plantillas.
    // Si UX 7.0 todavía no ha creado esa tabla, el chat sigue abriendo normalmente.
    $templateMap = [];
    try {
        $tpl = $pdo->prepare("SELECT mensaje_id, plantilla_nombre, plantilla_idioma, plantilla_categoria, variables_json
            FROM whatsapp_plantilla_envios
            WHERE conversacion_id = :id");
        $tpl->execute([':id' => $id]);
        foreach ($tpl->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $templateMap[(int)$row['mensaje_id']] = $row;
        }
    } catch (Throwable $ignored) {
        $templateMap = [];
    }

    $messages = [];
    foreach ($messageRows as $row) {
        $payload = wa_inbox_message_payload($row);
        $meta = $templateMap[(int)$row['id']] ?? null;
        if ($meta) {
            $variables = json_decode((string)($meta['variables_json'] ?? ''), true);
            $payload['plantilla'] = [
                'name' => (string)$meta['plantilla_nombre'],
                'language' => (string)($meta['plantilla_idioma'] ?? ''),
                'category' => (string)($meta['plantilla_categoria'] ?? ''),
                'variables' => is_array($variables) ? array_values($variables) : [],
            ];
        } else {
            $payload['plantilla'] = null;
        }
        $messages[] = $payload;
    }

    $window = wa_inbox_send_window($pdo, $id);

    // Abrir una conversación equivale a leer sus mensajes para este inbox compartido.
    if ((int)$conversation['no_leidos'] > 0) {
        $clear = $pdo->prepare("UPDATE whatsapp_conversaciones SET no_leidos = 0 WHERE id = :id");
        $clear->execute([':id' => $id]);
        $conversation['no_leidos'] = 0;
    }

    $firstHumanSeconds = null;
    if (!empty($conversation['primer_mensaje_en']) && !empty($conversation['primera_respuesta_humana_en'])) {
        try {
            $start = new DateTimeImmutable((string)$conversation['primer_mensaje_en']);
            $end = new DateTimeImmutable((string)$conversation['primera_respuesta_humana_en']);
            $firstHumanSeconds = max(0, $end->getTimestamp() - $start->getTimestamp());
        } catch (Throwable $e) {
            $firstHumanSeconds = null;
        }
    }

    $conversation['id'] = (int)$conversation['id'];
    $conversation['requiere_humano'] = (int)$conversation['requiere_humano'];
    $conversation['asignado_a'] = $conversation['asignado_a'] !== null ? (int)$conversation['asignado_a'] : null;
    $conversation['no_leidos'] = (int)$conversation['no_leidos'];
    $conversation['total_mensajes'] = (int)$conversation['total_mensajes'];
    $conversation['total_entrantes'] = (int)$conversation['total_entrantes'];
    $conversation['total_salientes'] = (int)$conversation['total_salientes'];
    $conversation['tiempo_primera_respuesta_humana_seg'] = $firstHumanSeconds;

    whatsapp_json(true, '', [
        'conversacion' => $conversation,
        'mensajes' => $messages,
        'ventana_24h' => $window,
        'usuarios' => auth_can_whatsapp('bandeja_gestionar') ? wa_inbox_users($pdo) : [],
        'can_modify' => auth_can_whatsapp_any(['bandeja_responder', 'bandeja_gestionar']),
        'can_reply' => auth_can_whatsapp('bandeja_responder'),
        'can_manage' => auth_can_whatsapp('bandeja_gestionar'),
    ]);
} catch (Throwable $e) {
    error_log('WhatsApp conversation: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo abrir la conversación.', [], 500);
}
