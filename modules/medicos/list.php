<?php
require_once __DIR__ . '/_helpers.php';auth_require_module_view('medicos');
try{
 $q=medicos_text($_GET['q']??'',120);$params=[];$where='1=1';if($q!==''){$where.=" AND (m.nombre LIKE :q OR e.nombre LIKE :q)";$params[':q']='%'.$q.'%';}
 $sql="SELECT m.id,m.nombre,m.estado,m.observaciones,m.color,m.activo,GROUP_CONCAT(DISTINCT CONCAT(e.id,'|',e.nombre,'|',me.principal) ORDER BY me.principal DESC,e.nombre SEPARATOR ';;') especialidades,COUNT(DISTINCT h.id) horarios FROM medicos_iphci m LEFT JOIN medicos_especialidades me ON me.medico_id=m.id LEFT JOIN especialidades_medicas e ON e.id=me.especialidad_id LEFT JOIN horarios_medicos h ON h.medico_id=m.id WHERE $where GROUP BY m.id ORDER BY m.activo DESC,m.nombre";
 $st=$pdo->prepare($sql);$st->execute($params);$records=$st->fetchAll(PDO::FETCH_ASSOC);foreach($records as &$r){$r['id']=(int)$r['id'];$r['activo']=(int)$r['activo'];$r['horarios']=(int)$r['horarios'];$r['especialidades']=medicos_parse_specialties($r['especialidades']);}
 $specialties=$pdo->query("SELECT e.id,e.nombre,e.activo,COUNT(me.medico_id) profesionales FROM especialidades_medicas e LEFT JOIN medicos_especialidades me ON me.especialidad_id=e.id GROUP BY e.id ORDER BY e.activo DESC,e.nombre")->fetchAll(PDO::FETCH_ASSOC);foreach($specialties as &$e){$e['id']=(int)$e['id'];$e['activo']=(int)$e['activo'];$e['profesionales']=(int)$e['profesionales'];}
 medicos_json(true,'',['records'=>$records,'specialties'=>$specialties,'can_modify'=>auth_can_modify_module('medicos')]);
}catch(Throwable $e){error_log('Error listando médicos: '.$e->getMessage());medicos_json(false,'No se pudo cargar el directorio.',[],500);}
