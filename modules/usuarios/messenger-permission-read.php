<?php
require_once __DIR__ . '/_helpers.php';

/*
 * Lectura mínima y sin migraciones para la pantalla de Usuarios.
 * Este endpoint NO llama messenger_ensure_permissions_schema(),
 * NO consulta INFORMATION_SCHEMA y NO ejecuta ALTER TABLE.
 */
try {
    global $pdo;

    $usuarioId = (int)($_GET['usuario_id'] ?? 0);
    if ($usuarioId <= 0) {
        usuarios_json(false, 'Usuario inválido.', [], 422);
    }

    $stmtRole = $pdo->prepare("
        SELECT rol
        FROM usuarios_sistema
        WHERE id = :id
        LIMIT 1
    ");
    $stmtRole->execute([':id' => $usuarioId]);
    $rol = (string)($stmtRole->fetchColumn() ?: '');

    if ($rol === '') {
        usuarios_json(false, 'La cuenta seleccionada no existe.', [], 404);
    }

    if ($rol === 'admin') {
        usuarios_json(true, '', ['canal_messenger' => 1]);
    }

    $enabled = 0;

    try {
        /*
         * Si una instalación antigua todavía no tiene canal_messenger,
         * MySQL devuelve "unknown column" de inmediato y usamos 0.
         * La columna se crea únicamente al GUARDAR permisos.
         */
        $stmt = $pdo->prepare("
            SELECT canal_messenger
            FROM usuarios_whatsapp_permisos
            WHERE usuario_id = :usuario_id
            LIMIT 1
        ");
        $stmt->execute([':usuario_id' => $usuarioId]);
        $enabled = (int)($stmt->fetchColumn() ?: 0) === 1 ? 1 : 0;
    } catch (Throwable $ignored) {
        $enabled = 0;
    }

    usuarios_json(true, '', ['canal_messenger' => $enabled]);

} catch (Throwable $e) {
    error_log('Usuarios Messenger permission read: ' . $e->getMessage());
    usuarios_json(false, 'No se pudo leer el permiso de Messenger.', [], 500);
}
