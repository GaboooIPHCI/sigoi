<?php
require_once __DIR__ . '/_helpers.php';
auth_require_whatsapp_permission('automatizacion_modificar');
auth_validate_csrf();

try {
    global $pdo;
    $data = whatsapp_request();

    $id = (int) ($data['id'] ?? 0);
    $name = whatsapp_clean_text($data['nombre'] ?? '', 120);
    $keywordsRaw = whatsapp_clean_text($data['palabras_clave'] ?? '', 4000);
    $response = whatsapp_clean_text($data['respuesta'] ?? '', 6000);
    $priority = max(1, min(9999, (int) ($data['prioridad'] ?? 100)));
    $active = array_key_exists('activa', $data) ? (!empty($data['activa']) ? 1 : 0) : 1;

    $keywords = whatsapp_keywords_array($keywordsRaw);

    if ($name === '') {
        whatsapp_json(false, 'Escribe un nombre para la regla.', [], 422);
    }
    if (!$keywords) {
        whatsapp_json(false, 'Agrega al menos una palabra o frase clave.', [], 422);
    }
    if ($response === '') {
        whatsapp_json(false, 'Escribe la respuesta de esta regla.', [], 422);
    }

    $keywordsStored = implode("\n", $keywords);
    $userId = auth_user_id();

    $isUpdate = $id > 0;

    if ($isUpdate) {
        $stmt = $pdo->prepare("UPDATE whatsapp_reglas
            SET nombre = :nombre,
                palabras_clave = :palabras,
                respuesta = :respuesta,
                prioridad = :prioridad,
                activa = :activa,
                actualizado_por = :usuario
            WHERE id = :id");
        $stmt->execute([
            ':nombre' => $name,
            ':palabras' => $keywordsStored,
            ':respuesta' => $response,
            ':prioridad' => $priority,
            ':activa' => $active,
            ':usuario' => $userId,
            ':id' => $id,
        ]);
        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare("SELECT 1 FROM whatsapp_reglas WHERE id = :id");
            $exists->execute([':id' => $id]);
            if (!$exists->fetchColumn()) {
                whatsapp_json(false, 'La regla que intentas editar ya no existe.', [], 404);
            }
        }
        $message = 'Regla actualizada correctamente.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO whatsapp_reglas
            (nombre, palabras_clave, respuesta, prioridad, activa, creado_por, actualizado_por)
            VALUES (:nombre, :palabras, :respuesta, :prioridad, :activa, :creado_por, :actualizado_por)");
        $stmt->execute([
            ':nombre' => $name,
            ':palabras' => $keywordsStored,
            ':respuesta' => $response,
            ':prioridad' => $priority,
            ':activa' => $active,
            ':creado_por' => $userId,
            ':actualizado_por' => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $message = 'Regla creada correctamente.';
    }

    auth_audit(
        $pdo,
        $isUpdate ? 'whatsapp_regla_actualizada' : 'whatsapp_regla_creada',
        auth_user_id(),
        auth_username(),
        'Regla WhatsApp: ' . $name
    );

    whatsapp_json(true, $message, ['id' => $id]);
} catch (Throwable $e) {
    error_log('WhatsApp rules save: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo guardar la regla.', [], 500);
}
