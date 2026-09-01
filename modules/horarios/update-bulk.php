<?php
require_once __DIR__ . '/_helpers.php';
auth_require_module_modify('horarios'); auth_validate_csrf();
try {
    $data=horarios_input(); $id=(int)($data['id']??0); $scope=horarios_text($data['alcance']??'',20); $row=horarios_payload($data,true);
    if(!in_array($scope,['weekday','future'],true)) horarios_json(false,'Alcance de edición inválido.',[],422);
    $target=$pdo->prepare("SELECT id,serie_id,fecha,hora_inicio,hora_fin FROM horarios_medicos WHERE id=:id LIMIT 1");$target->execute([':id'=>$id]);$old=$target->fetch(PDO::FETCH_ASSOC);
    if(!$old||empty($old['serie_id'])) horarios_json(false,'No se encontró el grupo de horarios.',[],404);
    $where="serie_id=:serie AND WEEKDAY(fecha)=WEEKDAY(:fecha) AND hora_inicio=:hora_anterior AND hora_fin=:fin_anterior";
    if($scope==='future')$where.=" AND fecha>=:desde";
    $sql="UPDATE horarios_medicos SET medico_id=:medico_id,especialidad_id=:especialidad_id,medico=:medico,especialidad=:especialidad,rama=:rama,hora_inicio=:inicio,hora_fin=:fin,modalidad=:modalidad,tipo_atencion=:tipo,estado=:estado,publicacion=:publicacion,cupos_totales=0,cupos_ocupados=0,observaciones=:observaciones,color=:color,actualizado_por=:usuario WHERE $where";
    $params=[':medico_id'=>$row['medico_id'],':especialidad_id'=>$row['especialidad_id'],':medico'=>$row['medico'],':especialidad'=>$row['especialidad'],':rama'=>$row['rama']?:null,':inicio'=>$row['hora_inicio'],':fin'=>$row['hora_fin'],':modalidad'=>$row['modalidad'],':tipo'=>$row['tipo_atencion'],':estado'=>$row['estado'],':publicacion'=>$row['publicacion'],':observaciones'=>$row['observaciones']?:null,':color'=>$row['color'],':usuario'=>auth_user_id(),':serie'=>$old['serie_id'],':fecha'=>$old['fecha'],':hora_anterior'=>$old['hora_inicio'],':fin_anterior'=>$old['hora_fin']];if($scope==='future')$params[':desde']=$old['fecha'];
    $stmt=$pdo->prepare($sql);$stmt->execute($params);horarios_sync_doctor_color($pdo,$row);auth_audit($pdo,'horarios_actualizados_grupo',auth_user_id(),auth_username(),$stmt->rowCount().' horario(s) de '.$row['medico']);
    horarios_json(true,$stmt->rowCount().' horario(s) actualizados.',['updated'=>$stmt->rowCount()]);
}catch(Throwable $e){error_log('Error actualizando horarios en grupo: '.$e->getMessage());horarios_json(false,'No se pudo actualizar el grupo de horarios.',[],500);}
