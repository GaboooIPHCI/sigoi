<?php
require_once __DIR__ . '/_helpers.php';
auth_require_whatsapp_permission('automatizacion_modificar');
auth_validate_csrf();

try {
    global $pdo;
    $data = whatsapp_request();

    $active = !empty($data['automatizacion_activa']) ? 1 : 0;
    $welcome = whatsapp_clean_text($data['mensaje_bienvenida'] ?? '', 4000);
    $fallback = whatsapp_clean_text($data['mensaje_no_reconocido'] ?? '', 4000);
    $schedules = $data['horarios'] ?? [];

    if ($welcome === '') {
        whatsapp_json(false, 'El mensaje de bienvenida no puede estar vacío.', [], 422);
    }
    if ($fallback === '') {
        whatsapp_json(false, 'La respuesta para consultas no reconocidas no puede estar vacía.', [], 422);
    }
    if (!is_array($schedules) || count($schedules) !== 7) {
        whatsapp_json(false, 'Debes configurar los 7 días de la semana.', [], 422);
    }

    $normalizedSchedules = [];
    foreach ($schedules as $row) {
        $day = (int) ($row['dia_semana'] ?? 0);
        $humanActive = !empty($row['atencion_humana_activa']) ? 1 : 0;
        $start = trim((string) ($row['hora_inicio'] ?? ''));
        $end = trim((string) ($row['hora_fin'] ?? ''));

        if ($day < 1 || $day > 7) {
            whatsapp_json(false, 'Hay un día de horario inválido.', [], 422);
        }

        if ($humanActive) {
            if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
                whatsapp_json(false, 'Completa la hora de inicio y fin de los días con atención humana.', [], 422);
            }
            if ($start >= $end) {
                whatsapp_json(false, 'La hora de inicio debe ser anterior a la hora de fin.', [], 422);
            }
        } else {
            $start = null;
            $end = null;
        }

        $normalizedSchedules[$day] = [$humanActive, $start, $end];
    }

    if (count($normalizedSchedules) !== 7) {
        whatsapp_json(false, 'No se puede repetir un día en los horarios.', [], 422);
    }

    $pdo->beginTransaction();

    $configStmt = $pdo->prepare("UPDATE whatsapp_configuracion
        SET automatizacion_activa = :activa,
            mensaje_bienvenida = :bienvenida,
            mensaje_no_reconocido = :fallback,
            zona_horaria = 'America/Lima'
        WHERE id = 1");
    $configStmt->execute([
        ':activa' => $active,
        ':bienvenida' => $welcome,
        ':fallback' => $fallback,
    ]);

    $scheduleStmt = $pdo->prepare("INSERT INTO whatsapp_horarios
        (dia_semana, atencion_humana_activa, hora_inicio, hora_fin)
        VALUES (:dia, :activa, :inicio, :fin)
        ON DUPLICATE KEY UPDATE
            atencion_humana_activa = VALUES(atencion_humana_activa),
            hora_inicio = VALUES(hora_inicio),
            hora_fin = VALUES(hora_fin)");

    foreach ($normalizedSchedules as $day => [$humanActive, $start, $end]) {
        $scheduleStmt->execute([
            ':dia' => $day,
            ':activa' => $humanActive,
            ':inicio' => $start,
            ':fin' => $end,
        ]);
    }

    $pdo->commit();

    auth_audit(
        $pdo,
        'whatsapp_configuracion_actualizada',
        auth_user_id(),
        auth_username(),
        'Configuración del asistente de WhatsApp actualizada.'
    );

    whatsapp_json(true, 'Configuración guardada correctamente.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('WhatsApp settings save: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo guardar la configuración.', [], 500);
}
