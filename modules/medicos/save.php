<?php
require_once __DIR__ . '/_helpers.php';auth_require_module_modify('medicos');auth_validate_csrf();
try{
 $d=medicos_input();$action=medicos_text($d['action']??'',40);
 if($action==='save_specialty'){$id=(int)($d['id']??0);$name=medicos_text($d['nombre']??'',120);if($name==='')medicos_json(false,'Escribe el nombre de la especialidad.',[],422);$st=$id?$pdo->prepare("UPDATE especialidades_medicas SET nombre=:n WHERE id=:id"):$pdo->prepare("INSERT INTO especialidades_medicas(nombre) VALUES(:n)");$p=[':n'=>$name];if($id)$p[':id']=$id;$st->execute($p);medicos_json(true,'Especialidad guardada.');}
 if($action==='toggle_specialty'){$st=$pdo->prepare("UPDATE especialidades_medicas SET activo=IF(activo=1,0,1) WHERE id=:id");$st->execute([':id'=>(int)($d['id']??0)]);medicos_json(true,'Estado actualizado.');}
 if($action==='toggle_doctor'){$st=$pdo->prepare("UPDATE medicos_iphci SET activo=IF(activo=1,0,1) WHERE id=:id");$st->execute([':id'=>(int)($d['id']??0)]);medicos_json(true,'Estado actualizado.');}
 if($action!=='save_doctor')medicos_json(false,'Acción inválida.',[],422);
 $id=(int)($d['id']??0);$name=medicos_text($d['nombre']??'',140);$status=medicos_text($d['estado']??'Activo',30);$notes=medicos_text($d['observaciones']??'',2000);$color=medicos_text($d['color']??'#5b21b6',16);$specialties=array_values(array_unique(array_filter(array_map('intval',is_array($d['especialidades']??null)?$d['especialidades']:[]))));$primary=(int)($d['principal_id']??($specialties[0]??0));
 if($name===''||!$specialties)medicos_json(false,'Completa el nombre y selecciona al menos una especialidad.',[],422);if(!in_array($status,['Activo','Temporalmente no disponible','Inactivo'],true))medicos_json(false,'Estado inválido.',[],422);
 if(!preg_match('/^#[0-9a-fA-F]{6}$/',$color))$color='#5b21b6';
 $pdo->beginTransaction();if($id){$st=$pdo->prepare("UPDATE medicos_iphci SET nombre=:n,estado=:e,observaciones=:o,color=:c WHERE id=:id");$st->execute([':n'=>$name,':e'=>$status,':o'=>$notes?:null,':c'=>$color,':id'=>$id]);}else{$st=$pdo->prepare("INSERT INTO medicos_iphci(nombre,estado,observaciones,color) VALUES(:n,:e,:o,:c)");$st->execute([':n'=>$name,':e'=>$status,':o'=>$notes?:null,':c'=>$color]);$id=(int)$pdo->lastInsertId();}
 $pdo->prepare("DELETE FROM medicos_especialidades WHERE medico_id=:id")->execute([':id'=>$id]);$link=$pdo->prepare("INSERT INTO medicos_especialidades(medico_id,especialidad_id,principal) VALUES(:m,:e,:p)");foreach($specialties as $sid)$link->execute([':m'=>$id,':e'=>$sid,':p'=>$sid===$primary?1:0]);
 $pdo->commit();auth_audit($pdo,'medico_guardado',auth_user_id(),auth_username(),$name);medicos_json(true,'Profesional guardado correctamente.',['id'=>$id]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Error guardando médico: '.$e->getMessage());medicos_json(false,'No se pudo guardar la información.',[],500);}
