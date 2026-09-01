<?php
require_once __DIR__ . '/_helpers.php';
auth_require_module_view('horarios');
try {
    $from = horarios_text($_GET['desde'] ?? date('Y-m-01'), 10);
    $to = horarios_text($_GET['hasta'] ?? date('Y-m-t'), 10);
    if (!horarios_date_valid($from) || !horarios_date_valid($to)) horarios_json(false, 'Rango de fechas inválido.', [], 422);
    $where = ['h.fecha BETWEEN :desde AND :hasta'];
    $params = [':desde' => $from, ':hasta' => $to];
    $search = horarios_text($_GET['busqueda'] ?? '', 140);
    if ($search !== '') {
        $where[] = '(COALESCE(m.nombre,h.medico) LIKE :busqueda_medico OR COALESCE(e.nombre,h.especialidad) LIKE :busqueda_especialidad)';
        $params[':busqueda_medico'] = '%'.$search.'%';
        $params[':busqueda_especialidad'] = '%'.$search.'%';
    }
    foreach (['medico', 'especialidad', 'estado', 'modalidad'] as $field) {
        $value = horarios_text($_GET[$field] ?? '', 140);
        if ($value !== '') {
            $column=$field==='medico'?'COALESCE(m.nombre,h.medico)':($field==='especialidad'?'COALESCE(e.nombre,h.especialidad)':'h.'.$field);
            if ($field === 'medico' || $field === 'especialidad') {
                $where[] = "$column LIKE :$field";
                $params[":$field"] = '%'.$value.'%';
            } else {
                $where[] = "$column = :$field";
                $params[":$field"] = $value;
            }
        }
    }
    $requested = horarios_text($_GET['publicacion'] ?? 'Publicado', 20);
    if (!auth_can_modify_module('horarios')) $requested = 'Publicado';
    if ($requested !== 'Todos') { $where[] = 'h.publicacion = :publicacion'; $params[':publicacion'] = $requested; }
    $stmt = $pdo->prepare("SELECT h.*,COALESCE(m.nombre,h.medico) medico,COALESCE(e.nombre,h.especialidad) especialidad,COALESCE(m.color,h.color) color FROM horarios_medicos h LEFT JOIN medicos_iphci m ON m.id=h.medico_id LEFT JOIN especialidades_medicas e ON e.id=h.especialidad_id WHERE " . implode(' AND ', $where) . " ORDER BY h.fecha, COALESCE(h.hora_inicio,'23:59'), especialidad, medico");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $filters = $pdo->query("SELECT DISTINCT COALESCE(m.nombre,h.medico) medico,COALESCE(e.nombre,h.especialidad) especialidad FROM horarios_medicos h LEFT JOIN medicos_iphci m ON m.id=h.medico_id LEFT JOIN especialidades_medicas e ON e.id=h.especialidad_id ORDER BY especialidad,medico")->fetchAll(PDO::FETCH_ASSOC);
    $summary = ['total' => count($records), 'publicados' => 0, 'borradores' => 0, 'profesionales' => [], 'especialidades' => []];
    foreach ($records as &$r) {
        $r['id'] = (int) $r['id'];
        $summary[$r['publicacion'] === 'Publicado' ? 'publicados' : 'borradores']++;
        $summary['profesionales'][$r['medico']] = true;
        $summary['especialidades'][$r['especialidad']] = true;
    }
    $summary['profesionales'] = count($summary['profesionales']);
    $summary['especialidades'] = count($summary['especialidades']);
    horarios_json(true, '', ['records' => $records, 'filters' => $filters, 'summary' => $summary, 'can_modify' => auth_can_modify_module('horarios')]);
} catch (Throwable $e) { horarios_json(false, 'No se pudieron cargar los horarios.', [], 500); }
