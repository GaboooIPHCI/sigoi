<?php
require_once __DIR__ . '/_helpers.php';
auth_validate_csrf();

try {
    global $pdo;
    $data = usuarios_request();
    $id = (int) ($data['id'] ?? 0);
    $password = (string) ($data['password'] ?? '');

    if ($id <= 0) usuarios_json(false, 'ID inválido.', [], 422);
    if (strlen($password) < 4) usuarios_json(false, 'La contraseña debe tener mínimo 4 caracteres.', [], 422);

    $stmt = $pdo->prepare("UPDATE usuarios_sistema SET password_hash = :password_hash WHERE id = :id");
    $stmt->execute([
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':id' => $id,
    ]);

    auth_audit($pdo, 'password_actualizado', auth_user_id(), auth_username(), 'Cambio de contraseña para usuario ID: ' . $id);
    usuarios_json(true, 'Contraseña actualizada correctamente.');
} catch (Throwable $e) {
    error_log('Usuarios reset-password: ' . $e->getMessage());
    usuarios_json(false, 'No se pudo actualizar la contraseña.', [], 500);
}
