<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_view('campanias');

try {
    global $pdo;

    $search = normalizeText($_GET['search'] ?? '');
    $estado = normalizeText($_GET['estado'] ?? '');
    $fechaDesde = normalizeText($_GET['fecha_inicio_desde'] ?? '');
    $fechaHasta = normalizeText($_GET['fecha_inicio_hasta'] ?? '');

    $sql = "
        SELECT 
            c.id,
            c.nombre,
            c.descripcion,
            c.fecha_inicio,
            c.estado,
            COUNT(cr.id) AS total_registros
        FROM campanias c
        LEFT JOIN campania_registros cr ON cr.campania_id = c.id
        WHERE 1=1
    ";

    $params = [];

    if ($search !== '') {
        $sql .= " AND (c.nombre LIKE :search OR c.descripcion LIKE :search)";
        $params[':search'] = "%{$search}%";
    }

    if ($estado !== '' && validateEstadoCampania($estado)) {
        $sql .= " AND c.estado = :estado";
        $params[':estado'] = $estado;
    }

    $mes = normalizeText($_GET['mes'] ?? '');

    if ($mes !== '') {
        $sql .= " AND DATE_FORMAT(c.fecha_inicio, '%Y-%m') = :mes";
        $params[':mes'] = $mes;
    }

    $sql .= "
        GROUP BY c.id, c.nombre, c.descripcion, c.fecha_inicio, c.estado
        ORDER BY c.fecha_inicio DESC, c.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonResponse(true, 'Campañas cargadas correctamente', $rows);
} catch (Throwable $e) {
    jsonResponse(false, 'Error al listar campañas: ' . $e->getMessage(), null, 500);
}