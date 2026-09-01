<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../config/auth.php';
auth_require_module_modify('campanias');
auth_validate_csrf();


try {
    global $pdo;

    $data = requestData();

    $campaniaId = isset($data['campania_id']) ? (int) $data['campania_id'] : 0;
    $nombreApellido = normalizeText($data['nombre_apellido'] ?? '');
    $numeroTelefono = normalizeText($data['numero_telefono'] ?? '');
    $dni = normalizeText($data['dni'] ?? '');
    $confirmoCita = normalizeText($data['confirmo_cita'] ?? 'pendiente');
    $asistio = normalizeText($data['asistio'] ?? '');
    $monto = $data['monto'] ?? null;
    $queSeRealizo = normalizeText($data['que_se_realizo'] ?? '');
    $camposExtraValores = $data['campos_extra_valores'] ?? [];

    if ($campaniaId <= 0) {
        jsonResponse(false, 'ID de campaña inválido', null, 422);
    }

    if ($nombreApellido === '') {
        jsonResponse(false, 'El nombre y apellido es obligatorio', null, 422);
    }

    if ($numeroTelefono === '') {
        jsonResponse(false, 'El número de teléfono es obligatorio', null, 422);
    }

    if (!in_array($confirmoCita, ['si', 'no', 'pendiente'], true)) {
        $confirmoCita = 'pendiente';
    }

    if ($asistio !== '' && !in_array($asistio, ['asistio', 'no_asistio'], true)) {
        jsonResponse(false, 'El valor de asistencia no es válido', null, 422);
    }

    if ($monto === '' || $monto === null) {
        $monto = null;
    } elseif (!is_numeric($monto)) {
        jsonResponse(false, 'El monto no es válido', null, 422);
    }

    $checkStmt = $pdo->prepare("SELECT id FROM campanias WHERE id = :id LIMIT 1");
    $checkStmt->execute([':id' => $campaniaId]);

    if (!$checkStmt->fetch()) {
        jsonResponse(false, 'La campaña no existe', null, 404);
    }

    $camposValidosStmt = $pdo->prepare("
        SELECT id 
        FROM campania_campos_extra 
        WHERE campania_id = :campania_id
    ");
    $camposValidosStmt->execute([':campania_id' => $campaniaId]);
    $camposValidos = array_map('intval', $camposValidosStmt->fetchAll(PDO::FETCH_COLUMN));

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO campania_registros (
            campania_id,
            nombre_apellido,
            numero_telefono,
            dni,
            confirmo_cita,
            asistio,
            monto,
            que_se_realizo
        )
        VALUES (
            :campania_id,
            :nombre_apellido,
            :numero_telefono,
            :dni,
            :confirmo_cita,
            :asistio,
            :monto,
            :que_se_realizo
        )
    ");

    $stmt->execute([
        ':campania_id' => $campaniaId,
        ':nombre_apellido' => $nombreApellido,
        ':numero_telefono' => $numeroTelefono,
        ':dni' => $dni !== '' ? $dni : null,
        ':confirmo_cita' => $confirmoCita,
        ':asistio' => $asistio !== '' ? $asistio : null,
        ':monto' => $monto,
        ':que_se_realizo' => $queSeRealizo !== '' ? $queSeRealizo : null
    ]);

    $registroId = (int) $pdo->lastInsertId();

    if (is_array($camposExtraValores) && !empty($camposExtraValores)) {
        $extraStmt = $pdo->prepare("
            INSERT INTO campania_registro_campos_extra (
                registro_id,
                campo_extra_id,
                valor
            ) VALUES (
                :registro_id,
                :campo_extra_id,
                :valor
            )
        ");

        foreach ($camposExtraValores as $campo) {
            $campoExtraId = isset($campo['campo_extra_id']) ? (int) $campo['campo_extra_id'] : 0;
            $valor = isset($campo['valor']) ? trim((string)$campo['valor']) : '';

            if ($campoExtraId <= 0) {
                continue;
            }

            if (!in_array($campoExtraId, $camposValidos, true)) {
                continue;
            }

            $extraStmt->execute([
                ':registro_id' => $registroId,
                ':campo_extra_id' => $campoExtraId,
                ':valor' => $valor !== '' ? $valor : null
            ]);
        }
    }

    $pdo->commit();

    jsonResponse(true, 'Registro creado correctamente', [
        'id' => $registroId
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    jsonResponse(false, 'Error al crear registro', null, 500);
}