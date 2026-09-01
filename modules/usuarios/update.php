<?php
require_once __DIR__ . '/_helpers.php';
auth_validate_csrf();

try {
    global $pdo;

    $data = usuarios_request();

    $id = (int) ($data['id'] ?? 0);
    $nombre = usuarios_clean($data['nombre'] ?? '');
    $usuario = strtolower(usuarios_clean($data['usuario'] ?? ''));

    if ($id <= 0) {
        usuarios_json(false, 'ID inválido.', [], 422);
    }

    if ($nombre === '') {
        usuarios_json(false, 'El nombre visible es obligatorio.', [], 422);
    }

    if (!usuarios_validate_username($usuario)) {
        usuarios_json(
            false,
            'El usuario debe tener 3 a 40 caracteres y no usar espacios.',
            [],
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Obtener el usuario actual
    |--------------------------------------------------------------------------
    | El rol se toma directamente desde la base de datos.
    | De esta forma no se puede cambiar Marketing a Recepción,
    | Centro Médico a Marketing, etc.
    |--------------------------------------------------------------------------
    */

    $currentStmt = $pdo->prepare("
        SELECT id, rol
        FROM usuarios_sistema
        WHERE id = :id
        LIMIT 1
    ");

    $currentStmt->execute([
        ':id' => $id
    ]);

    $currentUser = $currentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentUser) {
        usuarios_json(false, 'Usuario no encontrado.', [], 404);
    }

    $rol = $currentUser['rol'];

    /*
    |--------------------------------------------------------------------------
    | Comprobar que el nombre de usuario no esté repetido
    |--------------------------------------------------------------------------
    */

    $check = $pdo->prepare("
        SELECT id
        FROM usuarios_sistema
        WHERE usuario = :usuario
          AND id <> :id
        LIMIT 1
    ");

    $check->execute([
        ':usuario' => $usuario,
        ':id' => $id
    ]);

    if ($check->fetch()) {
        usuarios_json(false, 'Ese usuario ya existe.', [], 409);
    }

    /*
    |--------------------------------------------------------------------------
    | Actualizar datos
    |--------------------------------------------------------------------------
    | El rol NO se modifica.
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        UPDATE usuarios_sistema
        SET nombre = :nombre,
            usuario = :usuario
        WHERE id = :id
    ");

    $stmt->execute([
        ':nombre' => $nombre,
        ':usuario' => $usuario,
        ':id' => $id
    ]);

    /*
    |--------------------------------------------------------------------------
    | Actualizar sesión si el Admin editó su propia cuenta
    |--------------------------------------------------------------------------
    */

    if (auth_user_id() === $id) {
        $_SESSION['auth_user']['nombre'] = $nombre;
        $_SESSION['auth_user']['usuario'] = $usuario;
        $_SESSION['auth_user']['rol'] = $rol;
    }

    /*
    |--------------------------------------------------------------------------
    | Auditoría
    |--------------------------------------------------------------------------
    */

    auth_audit(
        $pdo,
        'usuario_actualizado',
        auth_user_id(),
        auth_username(),
        'Usuario actualizado: ' . $usuario . ' / rol: ' . $rol
    );

    usuarios_json(
        true,
        'Usuario actualizado correctamente.'
    );

} catch (Throwable $e) {

    usuarios_json(
        false,
        'No se pudo actualizar el usuario.',
        [
            'debug' => $e->getMessage()
        ],
        500
    );
}