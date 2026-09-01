<?php
require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/../../config/auth.php';

auth_require_module_view('campanias');

try {
    global $pdo;

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($id <= 0) {
        jsonResponse(false, 'ID de registro inválido', null, 422);
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            campania_id,
            nombre_apellido,
            numero_telefono,
            dni,
            confirmo_cita,
            asistio,
            monto,
            que_se_realizo,
            created_at,
            updated_at
        FROM campania_registros
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $base = $stmt->fetch();

    if (!$base) {
        jsonResponse(false, 'El registro no existe', null, 404);
    }

    $extraStmt = $pdo->prepare("
        SELECT
            rce.campo_extra_id,
            rce.valor,
            cce.nombre_interno,
            cce.etiqueta,
            cce.tipo
        FROM campania_registro_campos_extra rce
        INNER JOIN campania_campos_extra cce ON cce.id = rce.campo_extra_id
        WHERE rce.registro_id = :registro_id
        ORDER BY cce.orden ASC, cce.id ASC
    ");
    $extraStmt->execute([':registro_id' => $id]);
    $extras = $extraStmt->fetchAll();

    jsonResponse(true, 'Registro cargado correctamente', [
        'base' => $base,
        'campos_extra' => $extras
    ]);
} catch (Throwable $e) {
    jsonResponse(false, 'Error al obtener registro', null, 500);
}