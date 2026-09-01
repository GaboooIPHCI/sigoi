<?php
require_once __DIR__ . '/_helpers.php';
auth_require_module_modify('horarios'); auth_validate_csrf();
try {
    $data = horarios_input(); $id = (int) ($data['id'] ?? 0); $date = horarios_text($data['fecha_inicio'] ?? $data['fecha'] ?? '', 10); $row = horarios_payload($data, true);
    if ($id <= 0 || !horarios_date_valid($date)) horarios_json(false, 'Horario inválido.', [], 422);
    $stmt = $pdo->prepare("UPDATE horarios_medicos SET medico_id=:medico_id,especialidad_id=:especialidad_id,medico=:medico,especialidad=:especialidad,rama=:rama,fecha=:fecha,hora_inicio=:inicio,hora_fin=:fin,modalidad=:modalidad,tipo_atencion=:tipo,estado=:estado,publicacion=:publicacion,cupos_totales=:total,cupos_ocupados=:ocupados,observaciones=:observaciones,color=:color,actualizado_por=:usuario WHERE id=:id");
    $stmt->execute([':medico_id'=>$row['medico_id'],':especialidad_id'=>$row['especialidad_id'],':medico'=>$row['medico'],':especialidad'=>$row['especialidad'],':rama'=>$row['rama']?:null,':fecha'=>$date,':inicio'=>$row['hora_inicio'],':fin'=>$row['hora_fin'],':modalidad'=>$row['modalidad'],':tipo'=>$row['tipo_atencion'],':estado'=>$row['estado'],':publicacion'=>$row['publicacion'],':total'=>$row['cupos_totales'],':ocupados'=>$row['cupos_ocupados'],':observaciones'=>$row['observaciones']?:null,':color'=>$row['color'],':usuario'=>auth_user_id(),':id'=>$id]);
    if (!$stmt->rowCount()) { $check=$pdo->prepare('SELECT id FROM horarios_medicos WHERE id=?'); $check->execute([$id]); if (!$check->fetch()) horarios_json(false,'El horario ya no existe.',[],404); }
    horarios_sync_doctor_color($pdo,$row);
    auth_audit($pdo, 'horario_actualizado', auth_user_id(), auth_username(), $row['medico'] . ' - ' . $date);
    horarios_json(true, 'Horario actualizado correctamente.');
} catch (Throwable $e) { horarios_json(false, 'No se pudo actualizar el horario.', [], 500); }
