<?php
require_once __DIR__ . '/_helpers.php';
auth_validate_csrf();

try {
    global $pdo;

    $data = usuarios_request();
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        usuarios_json(false, 'ID inválido.', [], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | Obtener usuario
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT id, activo, usuario, rol
        FROM usuarios_sistema
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $id
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        usuarios_json(false, 'Usuario no encontrado.', [], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Proteger la cuenta Administrador
    |--------------------------------------------------------------------------
    | La única cuenta Admin no se puede desactivar.
    |--------------------------------------------------------------------------
    */

    if ($user['rol'] === 'admin') {
        usuarios_json(
            false,
            'La cuenta Administrador no se puede desactivar.',
            [],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Evitar desactivar la cuenta actualmente iniciada
    |--------------------------------------------------------------------------
    */

    if (auth_user_id() === $id) {
        usuarios_json(
            false,
            'No puedes desactivar tu propia cuenta.',
            [],
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Cambiar estado
    |--------------------------------------------------------------------------
    */

    $newStatus = (int) $user['activo'] ? 0 : 1;

    $update = $pdo->prepare("
        UPDATE usuarios_sistema
        SET activo = :activo
        WHERE id = :id
    ");

    $update->execute([
        ':activo' => $newStatus,
        ':id' => $id
    ]);

    /*
    |--------------------------------------------------------------------------
    | Auditoría
    |--------------------------------------------------------------------------
    */

    auth_audit(
        $pdo,
        $newStatus ? 'usuario_activado' : 'usuario_desactivado',
        auth_user_id(),
        auth_username(),
        'Usuario: ' . $user['usuario']
    );

    usuarios_json(
        true,
        'Estado actualizado correctamente.',
        [
            'activo' => $newStatus
        ]
    );

} catch (Throwable $e) {

    usuarios_json(
        false,
        'No se pudo actualizar el estado.',
        [
            'debug' => $e->getMessage()
        ],
        500
    );
}