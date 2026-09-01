<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/queue-worker-lib.php';

auth_require_login();

if (auth_user_role() !== 'admin') {
    auth_json_response([
        'ok' => false,
        'message' => 'Solo un administrador puede abrir el diagnóstico de Instagram.',
    ], 403);
}

instagram_ensure_schema($pdo);
ig_queue_ensure_storage();

$pending = count(glob(ig_queue_dir('pending') . '/*.json') ?: []);
$processing = count(glob(ig_queue_dir('processing') . '/*.json') ?: []);
$failed = count(glob(ig_queue_dir('failed') . '/*.json') ?: []);

$conversations = (int)$pdo->query("SELECT COUNT(*) FROM instagram_conversaciones")->fetchColumn();
$messages = (int)$pdo->query("SELECT COUNT(*) FROM instagram_mensajes")->fetchColumn();
$incoming = (int)$pdo->query("SELECT COUNT(*) FROM instagram_mensajes WHERE direccion = 'entrante'")->fetchColumn();
$outgoing = (int)$pdo->query("SELECT COUNT(*) FROM instagram_mensajes WHERE direccion = 'saliente'")->fetchColumn();

$last = $pdo->query("SELECT
        m.direccion, m.origen, m.tipo, m.estado_envio, m.creado_en,
        c.username, c.nombre_contacto
    FROM instagram_mensajes m
    INNER JOIN instagram_conversaciones c ON c.id = m.conversacion_id
    ORDER BY m.creado_en DESC, m.id DESC
    LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;

$heartbeatPath = ig_queue_root() . '/cron-heartbeat.json';
$heartbeat = null;
$cronActive = false;
if (is_file($heartbeatPath)) {
    $raw = @file_get_contents($heartbeatPath);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $heartbeat = $decoded['ran_at'] ?? null;
        $timestamp = $heartbeat ? strtotime((string)$heartbeat) : false;
        $cronActive = $timestamp !== false && (time() - $timestamp) <= 150;
    }
}

auth_json_response([
    'ok' => true,
    'service' => 'instagram-meta',
    'phase' => '2-reception-db',
    'configuration' => [
        'account_id' => defined('INSTAGRAM_META_ACCOUNT_ID') ? (string)INSTAGRAM_META_ACCOUNT_ID : null,
        'access_token_ready' => ig_meta_token_is_configured(),
        'app_secret_ready' => ig_meta_app_secret_is_configured(),
    ],
    'queue' => [
        'pending' => $pending,
        'processing' => $processing,
        'failed' => $failed,
        'cron_24h_active' => $cronActive,
        'cron_last_run' => $heartbeat,
    ],
    'database' => [
        'conversations' => $conversations,
        'messages' => $messages,
        'incoming' => $incoming,
        'outgoing' => $outgoing,
        'last_message' => $last,
    ],
]);
