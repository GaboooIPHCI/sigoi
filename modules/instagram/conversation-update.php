<?php

declare(strict_types=1);

require_once __DIR__ . '/_inbox_helpers.php';
auth_validate_csrf();
auth_require_whatsapp_channel('instagram');

try {
    global $pdo;
    instagram_ensure_schema($pdo);

    $data = instagram_request();
    $id = (int)($data['id'] ?? 0);
    $action = instagram_clean_text($data['action'] ?? '', 40);

    if ($action === 'read') auth_require_whatsapp_permission('bandeja_ver');
    else auth_require_whatsapp_permission('bandeja_gestionar');

    if ($id <= 0) instagram_json(false, 'Conversación inválida.', [], 422);
    if (!ig_inbox_conversation_row($pdo, $id)) instagram_json(false, 'La conversación ya no existe.', [], 404);

    if ($action === 'resolve') {
        $pdo->prepare("UPDATE instagram_conversaciones SET estado = 'cerrada', requiere_humano = 0, resuelto_en = NOW() WHERE id = :id")
            ->execute([':id' => $id]);
        $message = 'Conversación marcada como resuelta.';
    } elseif ($action === 'reopen') {
        $pdo->prepare("UPDATE instagram_conversaciones SET estado = 'abierta', resuelto_en = NULL WHERE id = :id")
            ->execute([':id' => $id]);
        $message = 'Conversación reabierta.';
    } elseif ($action === 'pending') {
        $pdo->prepare("UPDATE instagram_conversaciones SET estado = 'abierta', requiere_humano = 1, resuelto_en = NULL WHERE id = :id")
            ->execute([':id' => $id]);
        $message = 'Conversación marcada como pendiente.';
    } elseif ($action === 'assign') {
        $userId = (int)($data['usuario_id'] ?? 0);
        if ($userId > 0) {
            $user = $pdo->prepare("SELECT id FROM usuarios_sistema WHERE id = :id AND activo = 1 LIMIT 1");
            $user->execute([':id' => $userId]);
            if (!$user->fetchColumn()) instagram_json(false, 'El usuario seleccionado no está disponible.', [], 422);
        }
        $pdo->prepare("UPDATE instagram_conversaciones SET asignado_usuario_id = :usuario WHERE id = :id")
            ->execute([':usuario' => $userId > 0 ? $userId : null, ':id' => $id]);
        $message = $userId > 0 ? 'Conversación asignada.' : 'Asignación eliminada.';
    } elseif ($action === 'contact_save') {
        $customName = instagram_clean_text($data['nombre_personalizado'] ?? '', 140);
        $notes = instagram_clean_text($data['notas_contacto'] ?? '', 4000);
        $pdo->prepare("UPDATE instagram_conversaciones SET nombre_personalizado = :nombre, notas_contacto = :notas WHERE id = :id")
            ->execute([
                ':nombre' => $customName !== '' ? $customName : null,
                ':notas' => $notes !== '' ? $notes : null,
                ':id' => $id,
            ]);
        $message = 'Datos del contacto guardados.';
    } elseif ($action === 'read') {
        $pdo->prepare("UPDATE instagram_conversaciones SET no_leidos = 0 WHERE id = :id")
            ->execute([':id' => $id]);
        $message = 'Conversación leída.';
    } else {
        instagram_json(false, 'Acción no reconocida.', [], 422);
    }

    auth_audit(
        $pdo,
        'instagram_conversacion_' . $action,
        auth_user_id(),
        auth_username(),
        json_encode(['conversacion_id' => $id, 'accion' => $action], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
    );

    instagram_json(true, $message, ['conversacion' => ig_inbox_conversation_row($pdo, $id)]);
} catch (Throwable $e) {
    error_log('Instagram conversation-update: ' . $e->getMessage());
    instagram_json(false, 'No se pudo actualizar la conversación de Instagram.', [], 500);
}
