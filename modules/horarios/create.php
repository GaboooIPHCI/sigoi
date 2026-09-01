<?php
require_once __DIR__ . '/_helpers.php';
auth_require_module_modify('horarios'); auth_validate_csrf();
try {
    $data = horarios_input(); $row = horarios_payload($data);
    $from = horarios_text($data['fecha_inicio'] ?? '', 10); $to = horarios_text($data['fecha_fin'] ?? $from, 10);
    if (!horarios_date_valid($from) || !horarios_date_valid($to) || $to < $from) horarios_json(false, 'Selecciona un rango de fechas válido.', [], 422);
    $start = new DateTime($from); $end = new DateTime($to); $days = max(0, (int) $start->diff($end)->format('%a'));
    if ($days > 366) horarios_json(false, 'El rango no puede superar un año.', [], 422);
    $daySchedules = is_array($data['dias_horarios'] ?? null) ? $data['dias_horarios'] : [];
    $scheduleByDay = [];
    foreach ($daySchedules as $schedule) {
        $day = (int) ($schedule['dia'] ?? 0);
        $startTime = horarios_text($schedule['hora_inicio'] ?? '', 5);
        $endTime = horarios_text($schedule['hora_fin'] ?? '', 5);
        if ($day < 1 || $day > 7 || !horarios_time_valid($startTime) || !horarios_time_valid($endTime) || $endTime <= $startTime) {
            horarios_json(false, 'Revisa los días y sus horas de atención.', [], 422);
        }
        $scheduleByDay[$day][] = ['inicio' => $startTime, 'fin' => $endTime];
    }
    if (!$scheduleByDay) horarios_json(false, 'Selecciona al menos un día de atención y define su horario.', [], 422);
    foreach ($scheduleByDay as &$blocks) {
        usort($blocks, function ($a, $b) { return strcmp($a['inicio'], $b['inicio']); });
        for ($i=1,$count=count($blocks);$i<$count;$i++) if ($blocks[$i]['inicio'] < $blocks[$i-1]['fin']) horarios_json(false,'Un mismo día contiene horarios superpuestos.',[],422);
    }
    unset($blocks);
    $series = bin2hex(random_bytes(12)); $dates = [];
    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        $dayNumber = (int) $d->format('N');
        if (isset($scheduleByDay[$dayNumber])) foreach ($scheduleByDay[$dayNumber] as $block) {
            $dates[] = ['fecha' => $d->format('Y-m-d'), 'inicio' => $block['inicio'], 'fin' => $block['fin']];
        }
    }
    if (!$dates) horarios_json(false, 'El rango no contiene los días seleccionados.', [], 422);
    $sql = "INSERT INTO horarios_medicos (serie_id,medico_id,especialidad_id,medico,especialidad,rama,fecha,hora_inicio,hora_fin,modalidad,tipo_atencion,estado,publicacion,cupos_totales,cupos_ocupados,observaciones,color,creado_por,actualizado_por) VALUES (:serie,:medico_id,:especialidad_id,:medico,:especialidad,:rama,:fecha,:inicio,:fin,:modalidad,:tipo,:estado,:publicacion,:total,:ocupados,:observaciones,:color,:creado_por,:actualizado_por)";
    $stmt = $pdo->prepare($sql); $pdo->beginTransaction(); horarios_sync_doctor_color($pdo,$row);
    foreach ($dates as $date) $stmt->execute([':serie'=>$series,':medico_id'=>$row['medico_id'],':especialidad_id'=>$row['especialidad_id'],':medico'=>$row['medico'],':especialidad'=>$row['especialidad'],':rama'=>$row['rama']?:null,':fecha'=>$date['fecha'],':inicio'=>$date['inicio'],':fin'=>$date['fin'],':modalidad'=>$row['modalidad'],':tipo'=>$row['tipo_atencion'],':estado'=>$row['estado'],':publicacion'=>$row['publicacion'],':total'=>0,':ocupados'=>0,':observaciones'=>$row['observaciones']?:null,':color'=>$row['color'],':creado_por'=>auth_user_id(),':actualizado_por'=>auth_user_id()]);
    $pdo->commit(); auth_audit($pdo, 'horarios_creados', auth_user_id(), auth_username(), count($dates) . ' horario(s) para ' . $row['medico']);
    horarios_json(true, count($dates) === 1 ? 'Horario guardado.' : count($dates) . ' fechas creadas correctamente.', ['created' => count($dates)]);
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('Error al crear horario: ' . $e->getMessage()); horarios_json(false, 'No se pudo guardar el horario.', [], 500); }
