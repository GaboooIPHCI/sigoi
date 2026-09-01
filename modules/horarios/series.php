<?php
require_once __DIR__ . '/_helpers.php';
auth_require_module_modify('horarios');
try {
    $id = (int) ($_GET['id'] ?? 0);
    $target = $pdo->prepare("SELECT serie_id FROM horarios_medicos WHERE id=:id LIMIT 1");
    $target->execute([':id'=>$id]);
    $seriesId = (string) $target->fetchColumn();
    if ($id <= 0 || $seriesId === '') horarios_json(false, 'Este horario no pertenece a una programación grupal.', [], 422);
    $stmt = $pdo->prepare("SELECT h.*,COALESCE(m.nombre,h.medico) medico,COALESCE(e.nombre,h.especialidad) especialidad,COALESCE(m.color,h.color) color FROM horarios_medicos h LEFT JOIN medicos_iphci m ON m.id=h.medico_id LEFT JOIN especialidades_medicas e ON e.id=h.especialidad_id WHERE h.serie_id=:serie ORDER BY h.fecha,h.hora_inicio");
    $stmt->execute([':serie'=>$seriesId]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$records) horarios_json(false, 'La programación ya no existe.', [], 404);
    $first = $records[0]; $blocks = [];
    foreach ($records as $record) {
        $day = (int) (new DateTime($record['fecha']))->format('N');
        $key = $day.'|'.substr((string)$record['hora_inicio'],0,5).'|'.substr((string)$record['hora_fin'],0,5);
        $blocks[$key] = ['dia'=>$day,'hora_inicio'=>substr((string)$record['hora_inicio'],0,5),'hora_fin'=>substr((string)$record['hora_fin'],0,5)];
    }
    usort($blocks, function($a,$b){ return $a['dia']===$b['dia'] ? strcmp($a['hora_inicio'],$b['hora_inicio']) : $a['dia']-$b['dia']; });
    horarios_json(true, '', ['series'=>[
        'serie_id'=>$seriesId,'fecha_inicio'=>$records[0]['fecha'],'fecha_fin'=>$records[count($records)-1]['fecha'],
        'medico_id'=>(int)$first['medico_id'],'especialidad_id'=>(int)$first['especialidad_id'],'medico'=>$first['medico'],'especialidad'=>$first['especialidad'],
        'modalidad'=>$first['modalidad'],'estado'=>$first['estado'],'publicacion'=>$first['publicacion'],'observaciones'=>$first['observaciones'],'color'=>$first['color'],
        'dias_horarios'=>array_values($blocks),'total'=>count($records)
    ]]);
} catch (Throwable $e) { horarios_json(false, 'No se pudo cargar la programación grupal.', [], 500); }
