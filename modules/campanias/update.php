<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../config/auth.php';
auth_require_module_modify('campanias');
auth_validate_csrf();


try {
    global $pdo;

    $data = requestData();

    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $nombre = normalizeText($data['nombre'] ?? '');
    $descripcion = normalizeText($data['descripcion'] ?? '');
    $fechaInicio = normalizeText($data['fecha_inicio'] ?? '');
    $estado = normalizeText($data['estado'] ?? 'borrador');
    $camposExtra = normalizeCamposExtra($data['campos_extra'] ?? []);

    if ($id <= 0) {
        jsonResponse(false, 'ID de campaña inválido', null, 422);
    }

    if ($nombre === '') {
        jsonResponse(false, 'El nombre de la campaña es obligatorio', null, 422);
    }

    if ($fechaInicio === '') {
        jsonResponse(false, 'La fecha de inicio es obligatoria', null, 422);
    }

    if (!validateEstadoCampania($estado)) {
        jsonResponse(false, 'El estado de la campaña no es válido', null, 422);
    }

    $checkStmt = $pdo->prepare("SELECT id FROM campanias WHERE id = :id LIMIT 1");
    $checkStmt->execute([':id' => $id]);

    if (!$checkStmt->fetch()) {
        jsonResponse(false, 'La campaña no existe', null, 404);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE campanias
        SET nombre = :nombre,
            descripcion = :descripcion,
            fecha_inicio = :fecha_inicio,
            estado = :estado
        WHERE id = :id
    ");

    $stmt->execute([
        ':nombre' => $nombre,
        ':descripcion' => $descripcion !== '' ? $descripcion : null,
        ':fecha_inicio' => $fechaInicio,
        ':estado' => $estado,
        ':id' => $id
    ]);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM campania_registros WHERE campania_id = :campania_id");
    $stmt->execute([':campania_id' => $id]);
    $totalRegistros = (int) $stmt->fetchColumn();

    $tieneRegistros = $totalRegistros > 0;
    $mensaje = 'Campaña actualizada correctamente';

    if (!$tieneRegistros) {
        $deleteExtrasStmt = $pdo->prepare("DELETE FROM campania_campos_extra WHERE campania_id = :campania_id");
        $deleteExtrasStmt->execute([':campania_id' => $id]);

        if (!empty($camposExtra)) {
            $extraStmt = $pdo->prepare("
                INSERT INTO campania_campos_extra
                (campania_id, nombre_interno, etiqueta, tipo, requerido, opciones_json, orden, activo)
                VALUES
                (:campania_id, :nombre_interno, :etiqueta, :tipo, :requerido, :opciones_json, :orden, 1)
            ");

            foreach ($camposExtra as $campo) {
                $extraStmt->execute([
                    ':campania_id' => $id,
                    ':nombre_interno' => $campo['nombre_interno'],
                    ':etiqueta' => $campo['etiqueta'],
                    ':tipo' => $campo['tipo'],
                    ':requerido' => $campo['requerido'],
                    ':opciones_json' => $campo['opciones_json'],
                    ':orden' => $campo['orden']
                ]);
            }
        }
    } else {
        $mensaje .= '. Los campos extra no se pueden modificar porque la campaña ya tiene registros.';
    }

    $pdo->commit();

    jsonResponse(true, $mensaje, [
        'id' => $id,
        'bloqueo_campos_extra' => $tieneRegistros
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    jsonResponse(false, 'Error al actualizar campaña', null, 500);
}