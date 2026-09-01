<?php

declare(strict_types=1);

/*
 * Analítica multicanal S.I.G.O.I.
 * WhatsApp + Instagram, preparada para sumar nuevos canales.
 *
 * Este endpoint siempre intenta devolver JSON, incluso ante un error fatal,
 * para evitar respuestas HTML/blancas difíciles de diagnosticar desde el frontend.
 */

ob_start();

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if (!$error) {
        return;
    }

    $fatalTypes = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
    ];

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

auth_require_module_view('whatsapp');

function multicanal_json(
    bool $success,
    string $message = '',
    array $data = [],
    int $status = 200
): void {
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

    return $date instanceof DateTimeImmutable
        && $date->format('Y-m-d') === $value;
}

function multicanal_period_range(
    string $period,
    ?string $from,
    ?string $to,
    DateTimeZone $tz
): array {
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
            if (
                !$from ||
                !$to ||
                !multicanal_valid_date($from, $tz) ||
                !multicanal_valid_date($to, $tz)
            ) {
                throw new InvalidArgumentException(
                    'El rango personalizado no es válido.'
                );
            }

            $start = new DateTimeImmutable($from . ' 00:00:00', $tz);
            $toDate = new DateTimeImmutable($to . ' 00:00:00', $tz);

            if ($toDate < $start) {
                throw new InvalidArgumentException(
                    'La fecha final no puede ser anterior a la fecha inicial.'
                );
            }

            $days = (int)$start->diff($toDate)->format('%a') + 1;

            if ($days > 366) {
                throw new InvalidArgumentException(
                    'El rango personalizado no puede superar 366 días.'
                );
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

    return [
        'period' => $period,
        'start' => $start,
        'end' => $end,
    ];
}

function multicanal_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");

    $stmt->execute([
        ':table_name' => $table,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function multicanal_column_exists(
    PDO $pdo,
    string $table,
    string $column
): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");

    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function multicanal_channel_ready(
    PDO $pdo,
    array $config
): bool {
    $conversationTable = (string)$config['conversations'];
    $messageTable = (string)$config['messages'];

    if (
        !multicanal_table_exists($pdo, $conversationTable) ||
        !multicanal_table_exists($pdo, $messageTable)
    ) {
        return false;
    }

    $conversationColumns = [
        'id',
        'estado',
        'requiere_humano',
        'primer_mensaje_en',
        'ultimo_mensaje_en',
        'resuelto_en',
        'creado_en',
    ];

    $messageColumns = [
        'id',
        'conversacion_id',
        'direccion',
        'origen',
        'estado_envio',
        'creado_en',
    ];

    if (!empty($config['supports_read'])) {
        $messageColumns[] = 'leido_en';
    }

    foreach ($conversationColumns as $column) {
        if (!multicanal_column_exists(
            $pdo,
            $conversationTable,
            $column
        )) {
            return false;
        }
    }

    foreach ($messageColumns as $column) {
        if (!multicanal_column_exists(
            $pdo,
            $messageTable,
            $column
        )) {
            return false;
        }
    }

    return true;
}

function multicanal_scalar(
    PDO $pdo,
    string $sql,
    array $params = []
) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

function multicanal_origin_placeholders(
    array $origins,
    string $prefix,
    array &$params
): string {
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

    return (int)round(
        ($values[$middle - 1] + $values[$middle]) / 2
    );
}

function multicanal_channel_metrics(
    PDO $pdo,
    string $channel,
    array $config,
    string $start,
    string $end
): array {
    $conversationTable = (string)$config['conversations'];
    $messageTable = (string)$config['messages'];
    $humanOrigins = (array)$config['human_origins'];
    $supportsRead = !empty($config['supports_read']);

    $dateParams = [
        ':start' => $start,
        ':end' => $end,
    ];

    $validOutgoingSql = "
        direccion = 'saliente'
        AND COALESCE(estado_envio, '') NOT IN (
            'failed',
            'fallido',
            'error'
        )
    ";

    $conversations = (int)multicanal_scalar(
        $pdo,
        "
        SELECT COUNT(DISTINCT conversacion_id)
        FROM `{$messageTable}`
        WHERE creado_en >= :start
          AND creado_en < :end
        ",
        $dateParams
    );

    $newConversations = (int)multicanal_scalar(
        $pdo,
        "
        SELECT COUNT(*)
        FROM `{$conversationTable}`
        WHERE COALESCE(primer_mensaje_en, creado_en) >= :start
          AND COALESCE(primer_mensaje_en, creado_en) < :end
        ",
        $dateParams
    );

    $humanParams = $dateParams;

    $humanIn = multicanal_origin_placeholders(
        $humanOrigins,
        'human_',
        $humanParams
    );

    $attended = 0;

    if ($humanIn !== '') {
        $attended = (int)multicanal_scalar(
            $pdo,
            "
            SELECT COUNT(DISTINCT conversacion_id)
            FROM `{$messageTable}`
            WHERE {$validOutgoingSql}
              AND origen IN ({$humanIn})
              AND creado_en >= :start
              AND creado_en < :end
            ",
            $humanParams
        );
    }

    $pending = (int)multicanal_scalar(
        $pdo,
        "
        SELECT COUNT(*)
        FROM `{$conversationTable}`
        WHERE requiere_humano = 1
          AND estado <> 'cerrada'
          AND ultimo_mensaje_en >= :start
          AND ultimo_mensaje_en < :end
        ",
        $dateParams
    );

    $resolved = (int)multicanal_scalar(
        $pdo,
        "
        SELECT COUNT(*)
        FROM `{$conversationTable}`
        WHERE resuelto_en IS NOT NULL
          AND resuelto_en >= :start
          AND resuelto_en < :end
        ",
        $dateParams
    );

    $incoming = (int)multicanal_scalar(
        $pdo,
        "
        SELECT COUNT(*)
        FROM `{$messageTable}`
        WHERE direccion = 'entrante'
          AND creado_en >= :start
          AND creado_en < :end
        ",
        $dateParams
    );

    $outgoing = (int)multicanal_scalar(
        $pdo,
        "
        SELECT COUNT(*)
        FROM `{$messageTable}`
        WHERE {$validOutgoingSql}
          AND creado_en >= :start
          AND creado_en < :end
        ",
        $dateParams
    );

    $read = null;
    $unread = null;
    $readRate = null;

    if ($supportsRead) {
        $read = (int)multicanal_scalar(
            $pdo,
            "
            SELECT COUNT(*)
            FROM `{$messageTable}`
            WHERE {$validOutgoingSql}
              AND leido_en IS NOT NULL
              AND creado_en >= :start
              AND creado_en < :end
            ",
            $dateParams
        );

        $unread = max(0, $outgoing - $read);

        if ($outgoing > 0) {
            $readRate = round(($read / $outgoing) * 100, 1);
        }
    }

    $responseTimes = [];
    $firstResponseTimes = [];

    if ($humanIn !== '') {
        $responseStmt = $pdo->prepare("
            SELECT
                id,
                conversacion_id,
                direccion,
                origen,
                creado_en
            FROM `{$messageTable}`
            WHERE creado_en >= :start
              AND creado_en < :end
              AND (
                  direccion = 'entrante'
                  OR (
                      {$validOutgoingSql}
                      AND origen IN ({$humanIn})
                  )
              )
            ORDER BY
                conversacion_id ASC,
                creado_en ASC,
                id ASC
        ");

        $responseStmt->execute($humanParams);

        $waitingSince = [];
        $firstRecorded = [];

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

            if (!isset($waitingSince[$conversationId])) {
                continue;
            }

            $seconds = max(
                0,
                $timestamp - $waitingSince[$conversationId]
            );

            $responseTimes[] = $seconds;

            if (!isset($firstRecorded[$conversationId])) {
                $firstResponseTimes[] = $seconds;
                $firstRecorded[$conversationId] = true;
            }

            unset($waitingSince[$conversationId]);
        }
    }

    $readCase = $supportsRead
        ? "SUM(CASE WHEN {$validOutgoingSql} AND leido_en IS NOT NULL THEN 1 ELSE 0 END)"
        : "0";

    $dailyStmt = $pdo->prepare("
        SELECT
            DATE(creado_en) AS fecha,
            COUNT(DISTINCT conversacion_id) AS conversaciones,
            SUM(
                CASE
                    WHEN direccion = 'entrante' THEN 1
                    ELSE 0
                END
            ) AS entrantes,
            SUM(
                CASE
                    WHEN {$validOutgoingSql} THEN 1
                    ELSE 0
                END
            ) AS salientes,
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
        'capabilities' => [
            'read_receipts' => $supportsRead,
        ],

        'conversaciones' => $conversations,
        'nuevas' => $newConversations,
        'atendidas' => $attended,
        'pendientes' => $pending,
        'resueltas' => $resolved,

        'mensajes_entrantes' => $incoming,
        'mensajes_salientes' => $outgoing,

        'vistos' => $read,
        'no_vistos' => $unread,
        'tasa_lectura_pct' => $readRate,

        'primera_respuesta_promedio_seg'
            => multicanal_average($firstResponseTimes),

        'respuesta_promedio_seg'
            => multicanal_average($responseTimes),

        'respuesta_mediana_seg'
            => multicanal_median($responseTimes),

        'muestras_primera_respuesta'
            => count($firstResponseTimes),

        'muestras_respuesta'
            => count($responseTimes),

        'daily' => $daily,

        '_first_response_sum'
            => array_sum($firstResponseTimes),

        '_response_sum'
            => array_sum($responseTimes),

        '_response_times'
            => $responseTimes,
    ];
}

function multicanal_build_daily(
    DateTimeImmutable $start,
    DateTimeImmutable $end,
    array $channelMetrics
): array {
    $days = [];

    for (
        $cursor = $start;
        $cursor < $end;
        $cursor = $cursor->modify('+1 day')
    ) {
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

        foreach ($metrics['daily'] as $row) {
            $date = (string)$row['fecha'];

            if (!isset($days[$date])) {
                continue;
            }

            $channelRow = [
                'conversaciones'
                    => (int)$row['conversaciones'],

                'mensajes_entrantes'
                    => (int)$row['mensajes_entrantes'],

                'mensajes_salientes'
                    => (int)$row['mensajes_salientes'],

                'vistos'
                    => (int)$row['vistos'],
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
        throw new RuntimeException(
            'La conexión a la base de datos no está disponible.'
        );
    }

    if (!function_exists('auth_whatsapp_permissions')) {
        throw new RuntimeException(
            'No se pudo cargar la configuración de permisos.'
        );
    }

    $permissions = auth_whatsapp_permissions();

    if (empty($permissions['analitica_ver'])) {
        multicanal_json(
            false,
            'No tienes permiso para ver la analítica.',
            [],
            403
        );
    }

    $registry = [
        'whatsapp' => [
            'label' => 'WhatsApp',
            'permission' => 'canal_whatsapp',
            'conversations' => 'whatsapp_conversaciones',
            'messages' => 'whatsapp_mensajes',
            'human_origins' => [
                'sigoi',
                'whatsapp_app',
                'ycloud',
            ],
            'supports_read' => true,
        ],

        'instagram' => [
            'label' => 'Instagram',
            'permission' => 'canal_instagram',
            'conversations' => 'instagram_conversaciones',
            'messages' => 'instagram_mensajes',
            'human_origins' => [
                'sigoi',
                'instagram_app',
            ],
            'supports_read' => true,
        ],

        /*
         * Messenger queda preparado como un adaptador adicional.
         * Cuando existan permiso + tablas, no hará falta reescribir
         * el dashboard ni las consultas agregadas.
         */
        'messenger' => [
            'label' => 'Messenger',
            'permission' => 'canal_messenger',
            'conversations' => 'messenger_conversaciones',
            'messages' => 'messenger_mensajes',
            'human_origins' => [
                'sigoi',
            ],
            'supports_read' => false,
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
        multicanal_json(
            false,
            'No hay canales disponibles para esta analítica.',
            [],
            403
        );
    }

    $requestedChannel = strtolower(trim(
        (string)($_GET['channel'] ?? $_GET['canal'] ?? 'all')
    ));

    if ($requestedChannel === '') {
        $requestedChannel = 'all';
    }

    if (
        $requestedChannel !== 'all' &&
        !isset($registry[$requestedChannel])
    ) {
        multicanal_json(
            false,
            'El canal solicitado no es válido.',
            [],
            422
        );
    }

    if (
        $requestedChannel !== 'all' &&
        !isset($available[$requestedChannel])
    ) {
        multicanal_json(
            false,
            'No tienes acceso a ese canal o todavía no está disponible.',
            [],
            403
        );
    }

    $period = strtolower(trim(
        (string)($_GET['period'] ?? '')
    ));

    /*
     * Compatibilidad con el selector antiguo de 7/30/90 días.
     * Se usa switch para mantener compatibilidad con PHP 7.4+.
     */
    if ($period === '' && isset($_GET['range'])) {
        $rangeNumber = (int)$_GET['range'];

        switch ($rangeNumber) {
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
        isset($_GET['from'])
            ? trim((string)$_GET['from'])
            : null,
        isset($_GET['to'])
            ? trim((string)$_GET['to'])
            : null,
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
        : [
            $requestedChannel
                => $available[$requestedChannel]
        ];

    $channelMetrics = [];

    foreach ($selected as $channel => $config) {
        $channelMetrics[] = multicanal_channel_metrics(
            $pdo,
            $channel,
            $config,
            $start,
            $end
        );
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
            'conversaciones',
            'nuevas',
            'atendidas',
            'pendientes',
            'resueltas',
            'mensajes_entrantes',
            'mensajes_salientes',
            'muestras_primera_respuesta',
            'muestras_respuesta',
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
            $readSupportedOutgoing +=
                (int)$metrics['mensajes_salientes'];
        }
    }

    if ($readSupportedOutgoing > 0) {
        $summary['tasa_lectura_pct'] = round(
            ($readSupportedRead / $readSupportedOutgoing) * 100,
            1
        );
    }

    if ($summary['muestras_primera_respuesta'] > 0) {
        $summary['primera_respuesta_promedio_seg'] = (int)round(
            $firstResponseSum /
            $summary['muestras_primera_respuesta']
        );
    }

    if ($summary['muestras_respuesta'] > 0) {
        $summary['respuesta_promedio_seg'] = (int)round(
            $responseSum /
            $summary['muestras_respuesta']
        );
    }

    $summary['respuesta_mediana_seg'] =
        multicanal_median($allResponseTimes);

    $byChannel = [];

    foreach ($channelMetrics as $metrics) {
        $row = $metrics;

        unset(
            $row['daily'],
            $row['_first_response_sum'],
            $row['_response_sum'],
            $row['_response_times']
        );

        $row['distribucion_pct'] =
            $summary['conversaciones'] > 0
                ? round(
                    (
                        (int)$row['conversaciones'] /
                        $summary['conversaciones']
                    ) * 100,
                    1
                )
                : 0.0;

        $byChannel[] = $row;
    }

    $availableChannels = [];

    foreach ($available as $key => $config) {
        $availableChannels[] = [
            'key' => $key,
            'label' => (string)$config['label'],
            'capabilities' => [
                'read_receipts'
                    => !empty($config['supports_read']),
            ],
        ];
    }

    multicanal_json(
        true,
        '',
        [
            'data' => [
                'filters' => [
                    'period' => (string)$range['period'],
                    'channel' => $requestedChannel,
                    'from' => $startDate->format('Y-m-d'),
                    'to' => $endDate
                        ->modify('-1 day')
                        ->format('Y-m-d'),
                ],

                'available_channels'
                    => $availableChannels,

                'summary'
                    => $summary,

                'by_channel'
                    => $byChannel,

                'daily'
                    => multicanal_build_daily(
                        $startDate,
                        $endDate,
                        $channelMetrics
                    ),
            ],
        ]
    );

} catch (InvalidArgumentException $e) {

    multicanal_json(
        false,
        $e->getMessage(),
        [],
        422
    );

} catch (Throwable $e) {

    error_log(
        'Multichannel analytics: '
        . $e->getMessage()
    );

    multicanal_json(
        false,
        'No se pudo cargar la analítica multicanal.',
        [
            'error_code' => 'analytics_runtime',
        ],
        500
    );
}
