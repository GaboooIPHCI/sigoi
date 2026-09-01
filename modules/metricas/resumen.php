<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_view('metricas');

function isValidMonth($month) {
    return is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month);
}

function monthRange($month) {
    $start = $month . '-01';
    $end = date('Y-m-d', strtotime($start . ' +1 month'));
    return [$start, $end];
}

function buildMonthWhere($field, $month, &$params) {
    if ($month && isValidMonth($month)) {
        [$start, $end] = monthRange($month);
        $params[':start_' . md5($field)] = $start;
        $params[':end_' . md5($field)] = $end;

        return " AND {$field} >= :start_" . md5($field) . " AND {$field} < :end_" . md5($field);
    }

    return "";
}

function getAtencionesMetricas(PDO $pdo, ?string $month = null) {
    $params = [];
    $whereMonth = buildMonthWhere('fecha_contacto', $month, $params);

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM atenciones
        WHERE 1=1 {$whereMonth}
    ");
    $stmt->execute($params);
    $totalAtenciones = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT canal, COUNT(*) AS total
        FROM atenciones
        WHERE 1=1 {$whereMonth}
          AND canal IS NOT NULL
          AND canal <> ''
        GROUP BY canal
        ORDER BY total DESC
    ");
    $stmt->execute($params);
    $porCanal = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT tipo_atencion, COUNT(*) AS total
        FROM atenciones
        WHERE 1=1 {$whereMonth}
          AND tipo_atencion IS NOT NULL
          AND tipo_atencion <> ''
        GROUP BY tipo_atencion
        ORDER BY total DESC
    ");
    $stmt->execute($params);
    $porTipoAtencion = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN LOWER(TRIM(status_cita)) = 'confirmado' THEN 1 ELSE 0 END) AS confirmados,
            SUM(CASE WHEN LOWER(TRIM(status_cita)) = 'no confirmado' THEN 1 ELSE 0 END) AS no_confirmados
        FROM atenciones
        WHERE 1=1 {$whereMonth}
    ");
    $stmt->execute($params);
    $resumenAtenciones = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total_atenciones' => $totalAtenciones,
        'atenciones_por_canal' => $porCanal,
        'atenciones_por_tipo' => $porTipoAtencion,
        'atenciones_resumen' => [
            'confirmados' => (int) ($resumenAtenciones['confirmados'] ?? 0),
            'no_confirmados' => (int) ($resumenAtenciones['no_confirmados'] ?? 0)
        ]
    ];
}

try {
    $mes = $_GET['mes'] ?? null;
    $comparar = isset($_GET['comparar']) && $_GET['comparar'] === '1';
    $mesComparacion = $_GET['mes_comparacion'] ?? null;

    if ($mes && !isValidMonth($mes)) {
        echo json_encode([
            'success' => false,
            'message' => 'Mes principal inválido'
        ]);
        exit;
    }

    if ($comparar && (!$mesComparacion || !isValidMonth($mesComparacion))) {
        echo json_encode([
            'success' => false,
            'message' => 'Mes de comparación inválido'
        ]);
        exit;
    }

    $metricasPrincipal = getAtencionesMetricas($pdo, $mes);

    $metricasComparacion = null;
    if ($comparar) {
        $metricasComparacion = getAtencionesMetricas($pdo, $mesComparacion);
    }

    // Convenios filtrados por fecha_realizar
    $paramsConvenios = [];
    $whereConvenios = buildMonthWhere('fecha_realizar', $mes, $paramsConvenios);

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM convenios
        WHERE 1=1 {$whereConvenios}
    ");
    $stmt->execute($paramsConvenios);
    $totalConvenios = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT derivado_a, COUNT(*) AS total
        FROM convenios
        WHERE 1=1 {$whereConvenios}
          AND derivado_a IS NOT NULL
          AND derivado_a <> ''
        GROUP BY derivado_a
        ORDER BY total DESC
    ");
    $stmt->execute($paramsConvenios);
    $porDerivadoA = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Campañas filtradas por fecha_inicio
    $paramsCampanias = [];
    $whereCampanias = buildMonthWhere('fecha_inicio', $mes, $paramsCampanias);

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM campanias
        WHERE 1=1 {$whereCampanias}
    ");
    $stmt->execute($paramsCampanias);
    $totalCampanias = (int) $stmt->fetchColumn();

    // Registros de campañas pertenecientes a campañas del mes
    $stmt = $pdo->prepare("
        SELECT COUNT(r.id)
        FROM campania_registros r
        INNER JOIN campanias c ON c.id = r.campania_id
        WHERE 1=1 {$whereCampanias}
    ");
    $stmt->execute($paramsCampanias);
    $totalRegistrosCampanias = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.nombre,
            c.estado,
            COUNT(r.id) AS total_registros,
            ROUND(COALESCE(SUM(r.monto), 0), 2) AS monto_total
        FROM campanias c
        LEFT JOIN campania_registros r ON r.campania_id = c.id
        WHERE 1=1 {$whereCampanias}
        GROUP BY c.id, c.nombre, c.estado
        ORDER BY c.id DESC
    ");
    $stmt->execute($paramsCampanias);
    $campaniasDetalle = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'filtros' => [
                'mes' => $mes,
                'comparar' => $comparar,
                'mes_comparacion' => $comparar ? $mesComparacion : null
            ],
            'totales' => [
                'atenciones' => $metricasPrincipal['total_atenciones'],
                'convenios' => $totalConvenios,
                'campanias' => $totalCampanias,
                'registros_campanias' => $totalRegistrosCampanias
            ],
            'atenciones_resumen' => $metricasPrincipal['atenciones_resumen'],
            'atenciones_por_canal' => $metricasPrincipal['atenciones_por_canal'],
            'atenciones_por_tipo' => $metricasPrincipal['atenciones_por_tipo'],
            'convenios_por_derivado' => $porDerivadoA,
            'campanias_detalle' => $campaniasDetalle,
            'comparacion' => $metricasComparacion
        ]
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al cargar resumen de métricas'
    ]);
}