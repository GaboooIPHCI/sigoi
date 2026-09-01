<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../config/auth.php';
auth_require_module_modify('campanias');
auth_validate_csrf();


try {
    global $pdo;

    $data = requestData();

    $nombre = normalizeText($data['nombre'] ?? '');
    $descripcion = normalizeText($data['descripcion'] ?? '');
    $fechaInicio = normalizeText($data['fecha_inicio'] ?? '');
    $estado = normalizeText($data['estado'] ?? 'borrador');
    $camposExtra = normalizeCamposExtra($data['campos_extra'] ?? []);

    if ($nombre === '') {
        jsonResponse(false, 'El nombre de la campaña es obligatorio', null, 422);
    }

    if ($fechaInicio === '') {
        jsonResponse(false, 'La fecha de inicio es obligatoria', null, 422);
    }

    if (!validateEstadoCampania($estado)) {
        jsonResponse(false, 'El estado de la campaña no es válido', null, 422);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO campanias (nombre, descripcion, fecha_inicio, estado)
        VALUES (:nombre, :descripcion, :fecha_inicio, :estado)
    ");

    $stmt->execute([
        ':nombre' => $nombre,
        ':descripcion' => $descripcion !== '' ? $descripcion : null,
        ':fecha_inicio' => $fechaInicio,
        ':estado' => $estado
    ]);

    $campaniaId = (int) $pdo->lastInsertId();

    if (!empty($camposExtra)) {
        $extraStmt = $pdo->prepare("
            INSERT INTO campania_campos_extra
            (campania_id, nombre_interno, etiqueta, tipo, requerido, opciones_json, orden, activo)
            VALUES
            (:campania_id, :nombre_interno, :etiqueta, :tipo, :requerido, :opciones_json, :orden, 1)
        ");

        foreach ($camposExtra as $campo) {
            $extraStmt->execute([
                ':campania_id' => $campaniaId,
                ':nombre_interno' => $campo['nombre_interno'],
                ':etiqueta' => $campo['etiqueta'],
                ':tipo' => $campo['tipo'],
                ':requerido' => $campo['requerido'],
                ':opciones_json' => $campo['opciones_json'],
                ':orden' => $campo['orden']
            ]);
        }
    }

    $pdo->commit();

    jsonResponse(true, 'Campaña creada correctamente', [
        'id' => $campaniaId
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    jsonResponse(false, 'Error al crear campaña: ' . $e->getMessage(), null, 500);
}