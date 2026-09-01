<?php
require_once __DIR__ . '/_helpers.php';
auth_require_whatsapp_permission('analitica_ver');

try {
    global $pdo;
    $range = (int)($_GET['range'] ?? 30);
    if (!in_array($range, [7, 30, 90], true)) {
        $range = 30;
    }

    $start = (new DateTimeImmutable('today', new DateTimeZone('America/Lima')))
        ->modify('-' . ($range - 1) . ' days')
        ->format('Y-m-d 00:00:00');

    $scalar = function (string $sql, array $params = []) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    };

    $activeConversations = (int)$scalar("SELECT COUNT(DISTINCT conversacion_id) FROM whatsapp_mensajes WHERE creado_en >= :start", [':start'=>$start]);
    $newContacts = (int)$scalar("SELECT COUNT(*) FROM whatsapp_conversaciones WHERE COALESCE(primer_mensaje_en, creado_en) >= :start", [':start'=>$start]);

    $summary = [
        'conversaciones' => $activeConversations,
        'contactos_nuevos' => $newContacts,
        'contactos_recurrentes' => max(0, $activeConversations - $newContacts),
        'mensajes_entrantes' => (int)$scalar("SELECT COUNT(*) FROM whatsapp_mensajes WHERE direccion='entrante' AND creado_en >= :start", [':start'=>$start]),
        'mensajes_salientes' => (int)$scalar("SELECT COUNT(*) FROM whatsapp_mensajes WHERE direccion='saliente' AND COALESCE(estado_envio,'') <> 'failed' AND creado_en >= :start", [':start'=>$start]),
        'respuestas_automaticas' => (int)$scalar("SELECT COUNT(*) FROM whatsapp_mensajes WHERE direccion='saliente' AND origen='automatizacion' AND COALESCE(estado_envio,'') <> 'failed' AND creado_en >= :start", [':start'=>$start]),
        'respuestas_humanas' => (int)$scalar("SELECT COUNT(*) FROM whatsapp_mensajes WHERE direccion='saliente' AND origen IN ('sigoi','whatsapp_app','ycloud') AND COALESCE(estado_envio,'') <> 'failed' AND creado_en >= :start", [':start'=>$start]),
        'fuera_horario' => (int)$scalar("SELECT COUNT(*) FROM whatsapp_mensajes WHERE direccion='entrante' AND fuera_horario=1 AND creado_en >= :start", [':start'=>$start]),
        'pendientes' => (int)$scalar("SELECT COUNT(*) FROM whatsapp_conversaciones WHERE requiere_humano=1 AND estado<>'cerrada'"),
        'pendientes_15m' => (int)$scalar("SELECT COUNT(*) FROM whatsapp_conversaciones WHERE requiere_humano=1 AND estado<>'cerrada' AND ultimo_mensaje_en IS NOT NULL AND ultimo_mensaje_en <= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"),
        'pendientes_60m' => (int)$scalar("SELECT COUNT(*) FROM whatsapp_conversaciones WHERE requiere_humano=1 AND estado<>'cerrada' AND ultimo_mensaje_en IS NOT NULL AND ultimo_mensaje_en <= DATE_SUB(NOW(), INTERVAL 60 MINUTE)"),
        'no_leidos' => (int)$scalar("SELECT COALESCE(SUM(no_leidos),0) FROM whatsapp_conversaciones"),
        'resueltas' => (int)$scalar("SELECT COUNT(*) FROM whatsapp_conversaciones WHERE resuelto_en IS NOT NULL AND resuelto_en >= :start", [':start'=>$start]),
        'fallidos' => (int)$scalar("SELECT COUNT(*) FROM whatsapp_mensajes WHERE direccion='saliente' AND estado_envio='failed' AND creado_en >= :start", [':start'=>$start]),
    ];

    $outgoingForRead = (int)$scalar("SELECT COUNT(*) FROM whatsapp_mensajes WHERE direccion='saliente' AND COALESCE(estado_envio,'') <> 'failed' AND creado_en >= :start", [':start'=>$start]);
    $readOutgoing = (int)$scalar("SELECT COUNT(*) FROM whatsapp_mensajes WHERE direccion='saliente' AND leido_en IS NOT NULL AND creado_en >= :start", [':start'=>$start]);
    $summary['tasa_lectura_pct'] = $outgoingForRead > 0 ? round(($readOutgoing / $outgoingForRead) * 100, 1) : null;

    $resolutionAvg = $scalar("SELECT AVG(TIMESTAMPDIFF(SECOND, COALESCE(primer_mensaje_en, creado_en), resuelto_en)) FROM whatsapp_conversaciones WHERE resuelto_en IS NOT NULL AND resuelto_en >= :start", [':start'=>$start]);
    $summary['tiempo_resolucion_promedio_seg'] = $resolutionAvg !== null ? (int)round((float)$resolutionAvg) : null;

    /*
     * Tiempo de respuesta humana por secuencia real de atención:
     * - El primer mensaje entrante abre una espera.
     * - Mensajes entrantes adicionales mantienen la misma espera.
     * - Respuestas automáticas NO cierran la espera humana.
     * - La primera salida de S.I.G.O.I. o WhatsApp Business la cierra.
     * Esto evita medir solo la primera respuesta histórica de cada contacto.
     */
    $responseStmt = $pdo->prepare("SELECT conversacion_id, direccion, origen, creado_en
        FROM whatsapp_mensajes
        WHERE creado_en >= :start
          AND (direccion = 'entrante' OR (direccion = 'saliente' AND origen IN ('sigoi','whatsapp_app','ycloud') AND COALESCE(estado_envio,'') <> 'failed'))
        ORDER BY conversacion_id ASC, creado_en ASC, id ASC");
    $responseStmt->execute([':start'=>$start]);
    $responseRows = $responseStmt->fetchAll(PDO::FETCH_ASSOC);
    $pendingByConversation = [];
    $times = [];

    foreach ($responseRows as $row) {
        $cid = (int)$row['conversacion_id'];
        $direction = (string)$row['direccion'];
        $origin = (string)($row['origen'] ?? '');
        $ts = strtotime((string)$row['creado_en']);
        if ($ts === false) continue;

        if ($direction === 'entrante') {
            if (!isset($pendingByConversation[$cid])) {
                $pendingByConversation[$cid] = $ts;
            }
            continue;
        }

        if ($direction === 'saliente' && in_array($origin, ['sigoi','whatsapp_app','ycloud'], true) && isset($pendingByConversation[$cid])) {
            $times[] = max(0, $ts - $pendingByConversation[$cid]);
            unset($pendingByConversation[$cid]);
        }
    }

    sort($times, SORT_NUMERIC);
    $avg = $times ? (int)round(array_sum($times) / count($times)) : null;
    $median = null;
    if ($times) {
        $count = count($times);
        $mid = intdiv($count, 2);
        $median = $count % 2 ? $times[$mid] : (int)round(($times[$mid - 1] + $times[$mid]) / 2);
    }
    $summary['respuesta_humana_promedio_seg'] = $avg;
    $summary['respuesta_humana_mediana_seg'] = $median;
    $summary['muestras_respuesta_humana'] = count($times);

    $withAuto = (int)$scalar("SELECT COUNT(DISTINCT conversacion_id) FROM whatsapp_mensajes WHERE direccion='saliente' AND origen='automatizacion' AND COALESCE(estado_envio,'') <> 'failed' AND creado_en >= :start", [':start'=>$start]);
    $summary['cobertura_automatica_pct'] = $summary['conversaciones'] > 0
        ? round(($withAuto / $summary['conversaciones']) * 100, 1)
        : 0.0;

    $topStmt = $pdo->prepare("SELECT r.nombre, COUNT(*) AS total
        FROM whatsapp_mensajes m
        INNER JOIN whatsapp_reglas r ON r.id = m.regla_id
        WHERE m.direccion='saliente' AND m.respuesta_automatica=1 AND COALESCE(m.estado_envio,'') <> 'failed' AND m.creado_en >= :start
        GROUP BY r.id, r.nombre
        ORDER BY total DESC, r.nombre ASC
        LIMIT 8");
    $topStmt->execute([':start'=>$start]);
    $topRules = $topStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($topRules as &$row) $row['total'] = (int)$row['total'];
    unset($row);

    $hourStmt = $pdo->prepare("SELECT HOUR(creado_en) AS hora, COUNT(*) AS total
        FROM whatsapp_mensajes
        WHERE direccion='entrante' AND creado_en >= :start
        GROUP BY HOUR(creado_en) ORDER BY hora");
    $hourStmt->execute([':start'=>$start]);
    $hourMap = [];
    foreach ($hourStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $hourMap[(int)$row['hora']] = (int)$row['total'];
    $hours = [];
    for ($i=0; $i<24; $i++) $hours[] = ['hora'=>$i, 'total'=>$hourMap[$i] ?? 0];

    $dayStmt = $pdo->prepare("SELECT DATE(creado_en) AS fecha,
        SUM(CASE WHEN direccion='entrante' THEN 1 ELSE 0 END) AS entrantes,
        SUM(CASE WHEN direccion='saliente' THEN 1 ELSE 0 END) AS salientes
        FROM whatsapp_mensajes
        WHERE creado_en >= :start
        GROUP BY DATE(creado_en) ORDER BY fecha");
    $dayStmt->execute([':start'=>$start]);
    $daily = $dayStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($daily as &$row) {
        $row['entrantes'] = (int)$row['entrantes'];
        $row['salientes'] = (int)$row['salientes'];
    }
    unset($row);

    $agentStmt = $pdo->prepare("SELECT
            CASE
                WHEN m.origen='sigoi' THEN COALESCE(u.nombre, 'S.I.G.O.I.')
                WHEN m.origen='whatsapp_app' THEN 'WhatsApp Business'
                WHEN m.origen='ycloud' THEN 'YCloud'
                ELSE COALESCE(u.nombre, m.origen)
            END AS nombre,
            m.origen, COUNT(*) AS total
        FROM whatsapp_mensajes m
        LEFT JOIN usuarios_sistema u ON u.id = m.usuario_id
        WHERE m.direccion='saliente' AND m.origen IN ('sigoi','whatsapp_app','ycloud') AND COALESCE(m.estado_envio,'') <> 'failed' AND m.creado_en >= :start
        GROUP BY 1, m.origen
        ORDER BY total DESC LIMIT 10");
    $agentStmt->execute([':start'=>$start]);
    $agents = $agentStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($agents as &$row) $row['total'] = (int)$row['total'];
    unset($row);

    $sourceStmt = $pdo->prepare("SELECT
            CASE
                WHEN origen_fuente IS NULL OR origen_fuente='' THEN 'Orgánico / WhatsApp'
                WHEN origen_fuente='meta_ad' THEN 'Meta Ads'
                ELSE origen_fuente
            END AS nombre,
            COUNT(*) AS total
        FROM whatsapp_conversaciones
        WHERE COALESCE(primer_mensaje_en, creado_en) >= :start
        GROUP BY 1
        ORDER BY total DESC
        LIMIT 8");
    $sourceStmt->execute([':start'=>$start]);
    $sources = $sourceStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sources as &$row) $row['total'] = (int)$row['total'];
    unset($row);

    whatsapp_json(true, '', [
        'range' => $range,
        'start' => $start,
        'summary' => $summary,
        'top_rules' => $topRules,
        'hours' => $hours,
        'daily' => $daily,
        'agents' => $agents,
        'sources' => $sources,
    ]);
} catch (Throwable $e) {
    error_log('WhatsApp analytics: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudieron cargar las métricas de WhatsApp.', [], 500);
}
