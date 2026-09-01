<?php
require_once __DIR__ . '/_helpers.php';

try {
    global $pdo;

    $stmt = $pdo->query("
        SELECT
            id,
            nombre,
            usuario,
            rol,
            activo,
            ultimo_acceso,
            created_at,
            updated_at
        FROM usuarios_sistema
        ORDER BY FIELD(
            rol,
            'admin',
            'marketing',
            'centro_medico',
            'recepcion'
        ), nombre ASC, id ASC
    ");

    usuarios_json(true, '', [
        'records' => $stmt->fetchAll()
    ]);

} catch (Throwable $e) {
    usuarios_json(
        false,
        'No se pudo cargar usuarios.',
        ['debug' => $e->getMessage()],
        500
    );
}