<?php

declare(strict_types=1);

require_once __DIR__ . '/../usuarios/_helpers.php';
require_once __DIR__ . '/../../config/messenger_schema.php';

try {
    global $pdo;

    messenger_ensure_permissions_schema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $userId = (int)($_GET['usuario_id'] ?? 0);

        if ($userId <= 0) {
            usuarios_json(false, 'Usuario inválido.', [], 422);
        }

        usuarios_json(true, '', [
            'usuario_id' => $userId,
            'canal_messenger'
                => messenger_channel_permission_for_user(
                    $pdo,
                    $userId
                )
                    ? 1
                    : 0,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        usuarios_json(false, 'Método no permitido.', [], 405);
    }

    auth_validate_csrf();

    $data = usuarios_request();
    $userId = (int)($data['usuario_id'] ?? 0);
    $enabled = !empty($data['canal_messenger']) ? 1 : 0;

    if ($userId <= 0) {
        usuarios_json(false, 'Usuario inválido.', [], 422);
    }

    $stmt = $pdo->prepare("
        SELECT rol
        FROM usuarios_sistema
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    $role = (string)($stmt->fetchColumn() ?: '');

    if ($role === '') {
        usuarios_json(false, 'Usuario no encontrado.', [], 404);
    }

    if ($role === 'admin') {
        usuarios_json(
            false,
            'Los permisos del Administrador están protegidos.',
            [],
            403
        );
    }

    /*
     * Garantiza que exista la fila de permisos detallados.
     * Las columnas existentes quedan en sus valores por defecto si
     * por alguna razón el usuario aún no tenía configuración.
     */
    $pdo->prepare("
        INSERT INTO usuarios_whatsapp_permisos (
            usuario_id,
            canal_messenger
        )
        VALUES (
            :usuario_id,
            :canal_messenger
        )
        ON DUPLICATE KEY UPDATE
            canal_messenger = VALUES(canal_messenger)
    ")->execute([
        ':usuario_id' => $userId,
        ':canal_messenger' => $enabled,
    ]);

    auth_audit(
        $pdo,
        'messenger_permiso_canal',
        auth_user_id(),
        auth_username(),
        json_encode([
            'usuario_id' => $userId,
            'canal_messenger' => $enabled,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
    );

    usuarios_json(true, 'Permiso de Messenger actualizado.', [
        'canal_messenger' => $enabled,
    ]);

} catch (Throwable $e) {
    error_log('Messenger permission: ' . $e->getMessage());

    usuarios_json(
        false,
        'No se pudo actualizar el permiso de Messenger.',
        [],
        500
    );
}
