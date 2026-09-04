<?php

declare(strict_types=1);

/*
 * Analítica multicanal S.I.G.O.I. v1.6.0
 * WhatsApp + Instagram + Messenger.
 *
 * Principios:
 * - Sin métricas individuales por agente.
 * - Compatible con PHP 7.4.
 * - No expone errores internos al navegador.
 * - Corrige respuestas cuyo mensaje entrante ocurrió antes del inicio del periodo.
 * - Reduce consultas de INFORMATION_SCHEMA y agregados redundantes.
 */

ob_start();

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$error['type'], $fatalTypes, true)) {
        return;
    }

    if (ob_get_length()) {
        ob_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo json_encode([
        'success' => false,
        'message' => 'No se pudo cargar la analítica multicanal.',
        'error_code' => 'analytics_fatal',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/messenger_schema.php';

auth_require_module_view('whatsapp');

function multicanal_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function multicanal_valid_date(string $value, DateTimeZone $tz): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function multicanal_period_range(string $period, ?string $from, ?string $to, DateTimeZone $tz): array
{
    $today = new DateTimeImmutable('today', $tz);

    switch ($period) {
        case 'today':
            $start = $today;
            $end = $today->modify('+1 day');
            break;
        case 'yesterday':
            $start = $today->modify('-1 day');
            $end = $today;
            break;
        case '7d':
            $start = $today->modify('-6 days');
            $end = $today->modify('+1 day');
            break;
        case '90d':
            $start = $today->modify('-89 days');
            $end = $today->modify('+1 day');
            break;
        case 'custom':
            if (!$from || !$to || !multicanal_valid_date($from, $tz) || !multicanal_valid_date($to, $tz)) {
                throw new InvalidArgumentException('El rango personalizado no es válido.');
            }
            $start = new DateTimeImmutable($from . ' 00:00:00', $tz);
            $toDate = new DateTimeImmutable($to . ' 00:00:00', $tz);
            if ($toDate < $start) {
                throw new InvalidArgumentException('La fecha final no puede ser anterior a la fecha inicial.');
            }
            $days = (int)$start->diff($toDate)->format('%a') + 1;
            if ($days > 366) {
                throw new InvalidArgumentException('El rango personalizado no puede superar 366 días.');
            }
            $end = $toDate->modify('+1 day');
            break;
        case '30d':
        default:
            $period = '30d';
            $start = $today->modify('-29 days');
            $end = $today->modify('+1 day');
            break;
    }

    return ['period' => $period, 'start' => $start, 'end' => $end];
}

/**
 * Valida las columnas requeridas con una sola consulta a INFORMATION_SCHEMA
 * por canal, en lugar de una consulta por cada columna.
 */
function multicanal_channel_ready(PDO $pdo, array $config): bool
{
    $conversationTable = (string)$config['conversations'];
    $messageTable = (string)$config['messages'];

    $stmt = $pdo->prepare("
        SELECT TABLE_NAME, COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN (:conversation_table, :message_table)
    ");
    $stmt->execute([
        ':conversation_table' => $conversationTable,
        ':message_table' => $messageTable,
    ]);

    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $table = (string)$row['TABLE_NAME'];
        $column = (string)$row['COLUMN_NAME'];
        if (!isset($columns[$table])) {
            $columns[$table] = [];
        }
        $columns[$table][$column] = true;
    }

    $conversationColumns = [
        'id', 'estado', 'requiere_humano', 'primer_mensaje_en',
        'ultimo_mensaje_en', 'resuelto_en', 'creado_en',
    ];
    $messageColumns = [
        'id', 'conversacion_id', 'direccion', 'origen',
        'estado_envio', 'creado_en',
    ];
    if (!empty($config['supports_read'])) {
        $messageColumns[] = 'leido_en';
    }

    foreach ($conversationColumns as $column) {
        if (empty($columns[$conversationTable][$column])) {
            return false;
        }
    }
    foreach ($messageColumns as $column) {
        if (empty($columns[$messageTable][$column])) {
            return false;
        }
    }

    return true;
}

function multicanal_origin_placeholders(array $origins, string $prefix, array &$params): string
{
    $placeholders = [];
    foreach (array_values($origins) as $index => $origin) {
        $name = ':' . $prefix . $index;
        $placeholders[] = $name;
        $params[$name] = $origin;
    }
    return implode(', ', $placeholders);
}

function multicanal_average(array $values): ?int
{
    if (!$values) {
        return null;
    }
    return (int)round(array_sum($values) / count($values));
}

function multicanal_median(array $values): ?int
{
    if (!$values) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $middle = intdiv($count, 2);
    if ($count % 2 === 1) {
        return (int)$values[$middle];
    }
    return (int)round(($values[$middle - 1] + $values[$middle]) / 2);
}

function multicanal_valid_outgoing_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return "{$prefix}direccion = 'saliente' AND COALESCE({$prefix}estado_envio, '') NOT IN ('failed','fallido','error')";
}

/**
 * Obtiene el último evento relevante anterior al periodo para las conversaciones
 * activas. Si ese evento fue entrante, la primera respuesta humana dentro del
 * periodo conserva el tiempo real de espera en vez de empezar a contar a medianoche.
 */
function multicanal_boundary_waiting(
    PDO $pdo,
    string $messageTable,
    array $conversationIds,
    array $humanOrigins,
    string $start
): array {
    if (!$conversationIds || !$humanOrigins) {
        return [];
    }

    $waiting = [];
    foreach (array_chunk(array_values(array_unique(array_map('intval', $conversationIds))), 150) as $chunkIndex => $chunk) {
        $params = [
            ':boundary_start_m' => $start,
            ':boundary_start_n' => $start,
        ];

        $conversationPlaceholders = [];
        foreach ($chunk as $index => $conversationId) {
            if ($conversationId <= 0) {
                continue;
            }
            $name = ':bc' . $chunkIndex . '_' . $index;
            $conversationPlaceholders[] = $name;
            $params[$name] = $conversationId;
        }
        if (!$conversationPlaceholders) {
            continue;
        }

        $humanM = multicanal_origin_placeholders($humanOrigins, 'bm' . $chunkIndex . '_', $params);
        $humanN = multicanal_origin_placeholders($humanOrigins, 'bn' . $chunkIndex . '_', $params);

        $validM = multicanal_valid_outgoing_sql('m') . " AND m.origen IN ({$humanM})";
        $validN = multicanal_valid_outgoing_sql('n') . " AND n.origen IN ({$humanN})";

        $sql = "
            SELECT m.id, m.conversacion_id, m.direccion, m.creado_en
            FROM `{$messageTable}` m
            WHERE m.conversacion_id IN (" . implode(', ', $conversationPlaceholders) . ")
              AND m.creado_en < :boundary_start_m
              AND (m.direccion = 'entrante' OR ({$validM}))
              AND NOT EXISTS (
                  SELECT 1
                  FROM `{$messageTable}` n
                  WHERE n.conversacion_id = m.conversacion_id
                    AND n.creado_en < :boundary_start_n
                    AND (n.direccion = 'entrante' OR ({$validN}))
                    AND (
                        n.creado_en > m.creado_en
                        OR (n.creado_en = m.creado_en AND n.id > m.id)
                    )
              )
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ((string)$row['direccion'] !== 'entrante') {
                continue;
            }
            $timestamp = strtotime((string)$row['creado_en']);
            if ($timestamp !== false) {
                $waiting[(int)$row['conversacion_id']] = $timestamp;
            }
        }
    }

    return $waiting;
}

function multicanal_response_metrics(
    PDO $pdo,
    string $messageTable,
    array $humanOrigins,
    string $start,
    string $end
): array {
    if (!$humanOrigins) {
        return [
            'attended' => 0,
            'response_times' => [],
            'first_response_times' => [],
        ];
    }

    $params = [':start' => $start, ':end' => $end];
    $humanIn = multicanal_origin_placeholders($humanOrigins, 'rh_', $params);
    $validOutgoing = multicanal_valid_outgoing_sql() . " AND origen IN ({$humanIn})";

    $activeStmt = $pdo->prepare("
        SELECT DISTINCT conversacion_id
        FROM `{$messageTable}`
        WHERE creado_en >= :start
          AND creado_en < :end
          AND (direccion = 'entrante' OR ({$validOutgoing}))
    ");
    $activeStmt->execute($params);
    $conversationIds = array_map('intval', $activeStmt->fetchAll(PDO::FETCH_COLUMN));

    $waitingSince = multicanal_boundary_waiting(
        $pdo,
        $messageTable,
        $conversationIds,
        $humanOrigins,
        $start
    );

    $responseStmt = $pdo->prepare("
        SELECT id, conversacion_id, direccion, origen, creado_en
        FROM `{$messageTable}`
        WHERE creado_en >= :start
          AND creado_en < :end
          AND (direccion = 'entrante' OR ({$validOutgoing}))
        ORDER BY conversacion_id ASC, creado_en ASC, id ASC
    ");
    $responseStmt->execute($params);

    $responseTimes = [];
    $firstResponseTimes = [];
    $firstRecorded = [];
    $attended = [];

    while ($row = $responseStmt->fetch(PDO::FETCH_ASSOC)) {
        $conversationId = (int)$row['conversacion_id'];
        $direction = (string)$row['direccion'];
        $timestamp = strtotime((string)$row['creado_en']);
        if ($timestamp === false) {
            continue;
        }

        if ($direction === 'entrante') {
            if (!isset($waitingSince[$conversationId])) {
                $waitingSince[$conversationId] = $timestamp;
            }
            continue;
        }

        $attended[$conversationId] = true;
        if (!isset($waitingSince[$conversationId])) {
            continue;
        }

        $seconds = max(0, $timestamp - $waitingSince[$conversationId]);
        $responseTimes[] = $seconds;
        if (!isset($firstRecorded[$conversationId])) {
            $firstResponseTimes[] = $seconds;
            $firstRecorded[$conversationId] = true;
        }
        unset($waitingSince[$conversationId]);
    }

    return [
        'attended' => count($attended),
        'response_times' => $responseTimes,
        'first_response_times' => $firstResponseTimes,
    ];
}

function multicanal_channel_metrics(PDO $pdo, string $channel, array $config, string $start, string $end): array
{
    $conversationTable = (string)$config['conversations'];
    $messageTable = (string)$config['messages'];
    $humanOrigins = (array)$config['human_origins'];
    $supportsRead = !empty($config['supports_read']);
    $dateParams = [':start' => $start, ':end' => $end];
    $validOutgoingSql = multicanal_valid_outgoing_sql();

    $messageStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT conversacion_id) AS conversaciones,
            SUM(CASE WHEN direccion = 'entrante' THEN 1 ELSE 0 END) AS entrantes,
            SUM(CASE WHEN {$validOutgoingSql} THEN 1 ELSE 0 END) AS salientes,
            " . ($supportsRead
                ? "SUM(CASE WHEN {$validOutgoingSql} AND leido_en IS NOT NULL THEN 1 ELSE 0 END)"
                : "0") . " AS vistos
        FROM `{$messageTable}`
        WHERE creado_en >= :start
          AND creado_en < :end
    ");
    $messageStmt->execute($dateParams);
    $messageAgg = $messageStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $conversationStmt = $pdo->prepare("
        SELECT
            SUM(CASE
                WHEN COALESCE(primer_mensaje_en, creado_en) >= :start_new
                 AND COALESCE(primer_mensaje_en, creado_en) < :end_new
                THEN 1 ELSE 0 END) AS nuevas,
            SUM(CASE
                WHEN requiere_humano = 1
                 AND estado <> 'cerrada'
                 AND ultimo_mensaje_en >= :start_pending
                 AND ultimo_mensaje_en < :end_pending
                THEN 1 ELSE 0 END) AS pendientes,
            SUM(CASE
                WHEN resuelto_en IS NOT NULL
                 AND resuelto_en >= :start_resolved
                 AND resuelto_en < :end_resolved
                THEN 1 ELSE 0 END) AS resueltas
        FROM `{$conversationTable}`
        WHERE (
                COALESCE(primer_mensaje_en, creado_en) >= :where_start_new
            AND COALESCE(primer_mensaje_en, creado_en) < :where_end_new
        ) OR (
                requiere_humano = 1
            AND estado <> 'cerrada'
            AND ultimo_mensaje_en >= :where_start_pending
            AND ultimo_mensaje_en < :where_end_pending
        ) OR (
                resuelto_en IS NOT NULL
            AND resuelto_en >= :where_start_resolved
            AND resuelto_en < :where_end_resolved
        )
    ");
    $conversationStmt->execute([
        ':start_new' => $start,
        ':end_new' => $end,
        ':start_pending' => $start,
        ':end_pending' => $end,
        ':start_resolved' => $start,
        ':end_resolved' => $end,
        ':where_start_new' => $start,
        ':where_end_new' => $end,
        ':where_start_pending' => $start,
        ':where_end_pending' => $end,
        ':where_start_resolved' => $start,
        ':where_end_resolved' => $end,
    ]);
    $conversationAgg = $conversationStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $response = multicanal_response_metrics($pdo, $messageTable, $humanOrigins, $start, $end);
    $responseTimes = (array)$response['response_times'];
    $firstResponseTimes = (array)$response['first_response_times'];

    $conversations = (int)($messageAgg['conversaciones'] ?? 0);
    $incoming = (int)($messageAgg['entrantes'] ?? 0);
    $outgoing = (int)($messageAgg['salientes'] ?? 0);
    $read = $supportsRead ? (int)($messageAgg['vistos'] ?? 0) : null;
    $unread = $supportsRead ? max(0, $outgoing - (int)$read) : null;
    $readRate = ($supportsRead && $outgoing > 0)
        ? round(((int)$read / $outgoing) * 100, 1)
        : null;

    $readCase = $supportsRead
        ? "SUM(CASE WHEN {$validOutgoingSql} AND leido_en IS NOT NULL THEN 1 ELSE 0 END)"
        : "0";

    $dailyStmt = $pdo->prepare("
        SELECT
            DATE(creado_en) AS fecha,
            COUNT(DISTINCT conversacion_id) AS conversaciones,
            SUM(CASE WHEN direccion = 'entrante' THEN 1 ELSE 0 END) AS entrantes,
            SUM(CASE WHEN {$validOutgoingSql} THEN 1 ELSE 0 END) AS salientes,
            {$readCase} AS vistos
        FROM `{$messageTable}`
        WHERE creado_en >= :start
          AND creado_en < :end
        GROUP BY DATE(creado_en)
        ORDER BY fecha ASC
    ");
    $dailyStmt->execute($dateParams);

    $daily = [];
    while ($row = $dailyStmt->fetch(PDO::FETCH_ASSOC)) {
        $daily[] = [
            'fecha' => (string)$row['fecha'],
            'conversaciones' => (int)$row['conversaciones'],
            'mensajes_entrantes' => (int)$row['entrantes'],
            'mensajes_salientes' => (int)$row['salientes'],
            'vistos' => (int)$row['vistos'],
        ];
    }

    return [
        'channel' => $channel,
        'label' => (string)$config['label'],
        'capabilities' => ['read_receipts' => $supportsRead],
        'conversaciones' => $conversations,
        'nuevas' => (int)($conversationAgg['nuevas'] ?? 0),
        'atendidas' => (int)$response['attended'],
        'pendientes' => (int)($conversationAgg['pendientes'] ?? 0),
        'resueltas' => (int)($conversationAgg['resueltas'] ?? 0),
        'mensajes_entrantes' => $incoming,
        'mensajes_salientes' => $outgoing,
        'vistos' => $read,
        'no_vistos' => $unread,
        'tasa_lectura_pct' => $readRate,
        'primera_respuesta_promedio_seg' => multicanal_average($firstResponseTimes),
        'respuesta_promedio_seg' => multicanal_average($responseTimes),
        'respuesta_mediana_seg' => multicanal_median($responseTimes),
        'muestras_primera_respuesta' => count($firstResponseTimes),
        'muestras_respuesta' => count($responseTimes),
        'daily' => $daily,
        '_first_response_sum' => array_sum($firstResponseTimes),
        '_response_sum' => array_sum($responseTimes),
        '_response_times' => $responseTimes,
    ];
}

function multicanal_build_daily(DateTimeImmutable $start, DateTimeImmutable $end, array $channelMetrics): array
{
    $days = [];
    for ($cursor = $start; $cursor < $end; $cursor = $cursor->modify('+1 day')) {
        $date = $cursor->format('Y-m-d');
        $days[$date] = [
            'fecha' => $date,
            'total' => [
                'conversaciones' => 0,
                'mensajes_entrantes' => 0,
                'mensajes_salientes' => 0,
                'vistos' => 0,
            ],
            'channels' => [],
        ];
    }

    foreach ($channelMetrics as $metrics) {
        $channel = (string)$metrics['channel'];
        foreach ($days as &$day) {
            $day['channels'][$channel] = [
                'conversaciones' => 0,
                'mensajes_entrantes' => 0,
                'mensajes_salientes' => 0,
                'vistos' => 0,
            ];
        }
        unset($day);

        foreach ((array)$metrics['daily'] as $row) {
            $date = (string)$row['fecha'];
            if (!isset($days[$date])) {
                continue;
            }
            $channelRow = [
                'conversaciones' => (int)$row['conversaciones'],
                'mensajes_entrantes' => (int)$row['mensajes_entrantes'],
                'mensajes_salientes' => (int)$row['mensajes_salientes'],
                'vistos' => (int)$row['vistos'],
            ];
            $days[$date]['channels'][$channel] = $channelRow;
            foreach ($channelRow as $key => $value) {
                $days[$date]['total'][$key] += $value;
            }
        }
    }

    return array_values($days);
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('La conexión a la base de datos no está disponible.');
    }
    if (!function_exists('auth_whatsapp_permissions')) {
        throw new RuntimeException('No se pudo cargar la configuración de permisos.');
    }

    $permissions = auth_whatsapp_permissions();

    /*
     * Messenger ya está migrado en v1.6.0. Se conserva el helper de permiso
     * existente para respetar la configuración actual de cada usuario.
     */
    $permissions['canal_messenger'] = messenger_channel_permission_for_user(
        $pdo,
        (int)auth_user_id()
    ) ? 1 : 0;

    if (empty($permissions['analitica_ver'])) {
        multicanal_json(false, 'No tienes permiso para ver la analítica.', [], 403);
    }

    $registry = [
        'whatsapp' => [
            'label' => 'WhatsApp',
            'permission' => 'canal_whatsapp',
            'conversations' => 'whatsapp_conversaciones',
            'messages' => 'whatsapp_mensajes',
            'human_origins' => ['sigoi', 'whatsapp_app', 'ycloud'],
            'supports_read' => true,
        ],
        'instagram' => [
            'label' => 'Instagram',
            'permission' => 'canal_instagram',
            'conversations' => 'instagram_conversaciones',
            'messages' => 'instagram_mensajes',
            'human_origins' => ['sigoi', 'instagram_app'],
            'supports_read' => true,
        ],
        'messenger' => [
            'label' => 'Messenger',
            'permission' => 'canal_messenger',
            'conversations' => 'messenger_conversaciones',
            'messages' => 'messenger_mensajes',
            'human_origins' => ['sigoi', 'messenger_app'],
            'supports_read' => true,
        ],
    ];

    $available = [];
    foreach ($registry as $key => $config) {
        $permissionKey = (string)$config['permission'];
        if (empty($permissions[$permissionKey])) {
            continue;
        }
        if (!multicanal_channel_ready($pdo, $config)) {
            continue;
        }
        $available[$key] = $config;
    }

    if (!$available) {
        multicanal_json(false, 'No hay canales disponibles para esta analítica.', [], 403);
    }

    $requestedChannel = strtolower(trim((string)($_GET['channel'] ?? $_GET['canal'] ?? 'all')));
    if ($requestedChannel === '') {
        $requestedChannel = 'all';
    }
    if ($requestedChannel !== 'all' && !isset($registry[$requestedChannel])) {
        multicanal_json(false, 'El canal solicitado no es válido.', [], 422);
    }
    if ($requestedChannel !== 'all' && !isset($available[$requestedChannel])) {
        multicanal_json(false, 'No tienes acceso a ese canal o todavía no está disponible.', [], 403);
    }

    $period = strtolower(trim((string)($_GET['period'] ?? '')));
    if ($period === '' && isset($_GET['range'])) {
        switch ((int)$_GET['range']) {
            case 7:
                $period = '7d';
                break;
            case 90:
                $period = '90d';
                break;
            default:
                $period = '30d';
                break;
        }
    }
    if ($period === '') {
        $period = '30d';
    }

    $tz = new DateTimeZone('America/Lima');
    $range = multicanal_period_range(
        $period,
        isset($_GET['from']) ? trim((string)$_GET['from']) : null,
        isset($_GET['to']) ? trim((string)$_GET['to']) : null,
        $tz
    );

    /** @var DateTimeImmutable $startDate */
    $startDate = $range['start'];
    /** @var DateTimeImmutable $endDate */
    $endDate = $range['end'];
    $start = $startDate->format('Y-m-d H:i:s');
    $end = $endDate->format('Y-m-d H:i:s');

    $selected = $requestedChannel === 'all'
        ? $available
        : [$requestedChannel => $available[$requestedChannel]];

    $channelMetrics = [];
    foreach ($selected as $channel => $config) {
        $channelMetrics[] = multicanal_channel_metrics($pdo, $channel, $config, $start, $end);
    }

    $summary = [
        'conversaciones' => 0,
        'nuevas' => 0,
        'atendidas' => 0,
        'pendientes' => 0,
        'resueltas' => 0,
        'mensajes_entrantes' => 0,
        'mensajes_salientes' => 0,
        'vistos' => 0,
        'no_vistos' => 0,
        'tasa_lectura_pct' => null,
        'primera_respuesta_promedio_seg' => null,
        'respuesta_promedio_seg' => null,
        'respuesta_mediana_seg' => null,
        'muestras_primera_respuesta' => 0,
        'muestras_respuesta' => 0,
    ];

    $firstResponseSum = 0;
    $responseSum = 0;
    $allResponseTimes = [];
    $readSupportedOutgoing = 0;
    $readSupportedRead = 0;

    foreach ($channelMetrics as $metrics) {
        foreach ([
            'conversaciones', 'nuevas', 'atendidas', 'pendientes', 'resueltas',
            'mensajes_entrantes', 'mensajes_salientes',
            'muestras_primera_respuesta', 'muestras_respuesta',
        ] as $key) {
            $summary[$key] += (int)$metrics[$key];
        }

        $firstResponseSum += (int)$metrics['_first_response_sum'];
        $responseSum += (int)$metrics['_response_sum'];
        foreach ((array)$metrics['_response_times'] as $seconds) {
            $allResponseTimes[] = (int)$seconds;
        }

        if (!empty($metrics['capabilities']['read_receipts'])) {
            $channelRead = (int)($metrics['vistos'] ?? 0);
            $channelUnread = (int)($metrics['no_vistos'] ?? 0);
            $summary['vistos'] += $channelRead;
            $summary['no_vistos'] += $channelUnread;
            $readSupportedRead += $channelRead;
            $readSupportedOutgoing += (int)$metrics['mensajes_salientes'];
        }
    }

    if ($readSupportedOutgoing > 0) {
        $summary['tasa_lectura_pct'] = round(($readSupportedRead / $readSupportedOutgoing) * 100, 1);
    }
    if ($summary['muestras_primera_respuesta'] > 0) {
        $summary['primera_respuesta_promedio_seg'] = (int)round(
            $firstResponseSum / $summary['muestras_primera_respuesta']
        );
    }
    if ($summary['muestras_respuesta'] > 0) {
        $summary['respuesta_promedio_seg'] = (int)round(
            $responseSum / $summary['muestras_respuesta']
        );
    }
    $summary['respuesta_mediana_seg'] = multicanal_median($allResponseTimes);

    $byChannel = [];
    foreach ($channelMetrics as $metrics) {
        $row = $metrics;
        unset($row['daily'], $row['_first_response_sum'], $row['_response_sum'], $row['_response_times']);
        $row['distribucion_pct'] = $summary['conversaciones'] > 0
            ? round(((int)$row['conversaciones'] / $summary['conversaciones']) * 100, 1)
            : 0.0;
        $byChannel[] = $row;
    }

    $availableChannels = [];
    foreach ($available as $key => $config) {
        $availableChannels[] = [
            'key' => $key,
            'label' => (string)$config['label'],
            'capabilities' => ['read_receipts' => !empty($config['supports_read'])],
        ];
    }

    multicanal_json(true, '', [
        'data' => [
            'filters' => [
                'period' => (string)$range['period'],
                'channel' => $requestedChannel,
                'from' => $startDate->format('Y-m-d'),
                'to' => $endDate->modify('-1 day')->format('Y-m-d'),
            ],
            'available_channels' => $availableChannels,
            'summary' => $summary,
            'by_channel' => $byChannel,
            'daily' => multicanal_build_daily($startDate, $endDate, $channelMetrics),
        ],
    ]);
} catch (InvalidArgumentException $e) {
    multicanal_json(false, $e->getMessage(), [], 422);
} catch (Throwable $e) {
    error_log('Multichannel analytics: ' . $e->getMessage());
    multicanal_json(false, 'No se pudo cargar la analítica multicanal.', [
        'error_code' => 'analytics_runtime',
    ], 500);
}
