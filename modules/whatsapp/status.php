<?php
require_once __DIR__ . '/_helpers.php';

try {
    global $pdo;

    $ycloudConfig = __DIR__ . '/../../config/whatsapp_ycloud.php';
    if (is_file($ycloudConfig)) {
        require_once $ycloudConfig;
    }
    require_once __DIR__ . '/automation.php';

    $canAutomationView = auth_can_whatsapp('automatizacion_ver');
    $canInboxView = auth_can_whatsapp('bandeja_ver');

    $config = [];
    $schedules = [];
    $rules = [];

    if ($canAutomationView) {
        $config = $pdo->query("SELECT id, automatizacion_activa, mensaje_bienvenida,
            mensaje_no_reconocido, zona_horaria, actualizado_en
            FROM whatsapp_configuracion WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

        $schedules = $pdo->query("SELECT dia_semana, atencion_humana_activa,
            TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio,
            TIME_FORMAT(hora_fin, '%H:%i') AS hora_fin
            FROM whatsapp_horarios ORDER BY dia_semana")->fetchAll(PDO::FETCH_ASSOC);

        $rules = $pdo->query("SELECT id, nombre, palabras_clave, respuesta, prioridad, activa,
            actualizado_en
            FROM whatsapp_reglas
            ORDER BY prioridad ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    $stats = [
        'reglas_activas' => (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_reglas WHERE activa = 1")->fetchColumn(),
        'mensajes_hoy' => (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_mensajes WHERE DATE(creado_en) = CURDATE()")->fetchColumn(),
        'respuestas_hoy' => (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_mensajes WHERE DATE(creado_en) = CURDATE() AND respuesta_automatica = 1")->fetchColumn(),
        'pendientes_humano' => (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_conversaciones WHERE requiere_humano = 1 AND estado <> 'cerrada'")->fetchColumn(),
        'no_leidos' => (int)$pdo->query("SELECT COALESCE(SUM(no_leidos),0) FROM whatsapp_conversaciones")->fetchColumn(),
        'conversaciones_abiertas' => (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_conversaciones WHERE estado <> 'cerrada'")->fetchColumn(),
    ];

    $recent = [];
    if ($canInboxView) {
        $recent = $pdo->query("SELECT id, telefono, nombre_contacto, estado, requiere_humano,
            no_leidos, ultimo_mensaje_en, ultimo_mensaje_preview
            FROM whatsapp_conversaciones
            ORDER BY COALESCE(ultimo_mensaje_en, creado_en) DESC
            LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    }

    $connected = function_exists('wa_auto_ycloud_is_configured') && wa_auto_ycloud_is_configured();
    $sender = defined('WHATSAPP_YCLOUD_SENDER') ? wa_auto_e164((string)WHATSAPP_YCLOUD_SENDER) : '';

    $cronHeartbeat = __DIR__ . '/../../storage/whatsapp_queue/cron-heartbeat.json';
    $cronLast = null;
    $cronActive = false;
    if (is_file($cronHeartbeat)) {
        $cronRaw = @file_get_contents($cronHeartbeat);
        $cronData = is_string($cronRaw) ? json_decode($cronRaw, true) : null;
        $cronLast = is_array($cronData) ? ($cronData['ran_at'] ?? null) : null;
        $cronTs = $cronLast ? strtotime((string)$cronLast) : false;
        $cronActive = $cronTs !== false && (time() - $cronTs) <= 150;
    }

    whatsapp_json(true, '', [
        'config' => $config ?: [],
        'horarios' => $schedules,
        'reglas' => $rules,
        'stats' => $stats,
        'conversaciones' => $recent,
        'integracion' => [
            'estado' => $connected ? 'connected' : 'partial',
            'texto' => $connected ? 'YCloud Coexistence conectado' : 'YCloud: recepción lista / envío pendiente',
            'numero' => $sender,
            'cron_24h_activo' => $cronActive,
            'cron_ultimo' => $cronLast,
        ],
    ]);
} catch (Throwable $e) {
    error_log('WhatsApp status: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo cargar el módulo de WhatsApp.', [], 500);
}
