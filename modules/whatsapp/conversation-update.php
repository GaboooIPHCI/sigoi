<?php
require_once __DIR__ . '/_inbox_helpers.php';
auth_validate_csrf();
auth_require_whatsapp_channel('whatsapp');

try {
    global $pdo;
    $data = whatsapp_request();
    $id = (int)($data['id'] ?? 0);
    $action = whatsapp_clean_text($data['action'] ?? '', 40);

    if ($action === 'read') {
        auth_require_whatsapp_permission('bandeja_ver');
    } else {
        auth_require_whatsapp_permission('bandeja_gestionar');
    }

    if ($id <= 0) {
        whatsapp_json(false, 'Conversación inválida.', [], 422);
    }

    $exists = wa_inbox_conversation_row($pdo, $id);
    if (!$exists) {
        whatsapp_json(false, 'La conversación ya no existe.', [], 404);
    }

    if ($action === 'resolve') {
        $stmt = $pdo->prepare("UPDATE whatsapp_conversaciones
            SET estado = 'cerrada', requiere_humano = 0, resuelto_en = NOW()
            WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $message = 'Conversación marcada como resuelta.';
    } elseif ($action === 'reopen') {
        $stmt = $pdo->prepare("UPDATE whatsapp_conversaciones
            SET estado = 'abierta', resuelto_en = NULL
            WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $message = 'Conversación reabierta.';
    } elseif ($action === 'pending') {
        $stmt = $pdo->prepare("UPDATE whatsapp_conversaciones
            SET estado = 'abierta', requiere_humano = 1, resuelto_en = NULL
            WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $message = 'Conversación marcada como pendiente.';
    } elseif ($action === 'assign') {
        $userId = (int)($data['usuario_id'] ?? 0);
        if ($userId > 0) {
            $user = $pdo->prepare("SELECT id FROM usuarios_sistema WHERE id = :id AND activo = 1 LIMIT 1");
            $user->execute([':id' => $userId]);
            if (!$user->fetchColumn()) {
                whatsapp_json(false, 'El usuario seleccionado no está disponible.', [], 422);
            }
        }
        $stmt = $pdo->prepare("UPDATE whatsapp_conversaciones SET asignado_a = :usuario WHERE id = :id");
        $stmt->execute([':usuario' => $userId > 0 ? $userId : null, ':id' => $id]);
        $message = $userId > 0 ? 'Conversación asignada.' : 'Asignación eliminada.';
    } elseif ($action === 'contact_save') {
        $customName = whatsapp_clean_text($data['nombre_personalizado'] ?? '', 140);
        $notes = trim((string)($data['notas_contacto'] ?? ''));
        if (function_exists('mb_substr')) {
            $notes = mb_substr($notes, 0, 4000, 'UTF-8');
        } else {
            $notes = substr($notes, 0, 4000);
        }
        $stmt = $pdo->prepare("UPDATE whatsapp_conversaciones
            SET nombre_personalizado = :nombre, notas_contacto = :notas
            WHERE id = :id");
        $stmt->execute([
            ':nombre' => $customName !== '' ? $customName : null,
            ':notas' => $notes !== '' ? $notes : null,
            ':id' => $id,
        ]);
        $message = 'Datos del contacto guardados.';
    } elseif ($action === 'read') {
        $stmt = $pdo->prepare("UPDATE whatsapp_conversaciones SET no_leidos = 0 WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $message = 'Conversación leída.';
    } else {
        whatsapp_json(false, 'Acción no reconocida.', [], 422);
    }

    auth_audit(
        $pdo,
        'whatsapp_conversacion_' . $action,
        auth_user_id(),
        auth_username(),
        json_encode([
            'conversacion_id' => $id,
            'accion' => $action,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
    );

    whatsapp_json(true, $message, ['conversacion' => wa_inbox_conversation_row($pdo, $id)]);
} catch (Throwable $e) {
    error_log('WhatsApp conversation-update: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo actualizar la conversación.', [], 500);
}
