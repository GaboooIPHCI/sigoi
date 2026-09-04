<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app_version.php';

if (!function_exists('auth_can_manage_users') || !auth_can_manage_users()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'No tienes permiso para consultar el estado del sistema.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sigoi_health_file_count(string $dir, string $pattern = '*.json'): int
{
    if (!is_dir($dir)) return 0;
    return count(glob(rtrim($dir, '/\\') . '/' . $pattern) ?: []);
}

function sigoi_health_read_heartbeat(string $file): array
{
    if (!is_file($file)) {
        return [
            'status' => 'warning',
            'label' => 'Sin señal todavía',
            'ran_at' => null,
            'age_seconds' => null,
            'result' => [],
        ];
    }

    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return [
            'status' => 'warning',
            'label' => 'Heartbeat no válido',
            'ran_at' => null,
            'age_seconds' => null,
            'result' => [],
        ];
    }

    $ranAt = trim((string)($data['ran_at'] ?? ''));
    $timestamp = $ranAt !== '' ? strtotime($ranAt) : false;
    $age = $timestamp !== false ? max(0, time() - (int)$timestamp) : null;
    $ok = !isset($data['ok']) || $data['ok'] !== false;

    if (!$ok) {
        $status = 'error';
        $label = 'Última ejecución con error';
    } elseif ($age === null) {
        $status = 'warning';
        $label = 'Sin fecha válida';
    } elseif ($age <= 180) {
        $status = 'ok';
        $label = 'Activo';
    } elseif ($age <= 600) {
        $status = 'warning';
        $label = 'Retrasado';
    } else {
        $status = 'error';
        $label = 'Sin ejecución reciente';
    }

    return [
        'status' => $status,
        'label' => $label,
        'ran_at' => $ranAt !== '' ? $ranAt : null,
        'age_seconds' => $age,
        'result' => is_array($data['result'] ?? null) ? $data['result'] : [],
    ];
}

function sigoi_health_storage_scan(string $dir, int $maxFiles = 20000): array
{
    if (!is_dir($dir)) {
        return ['bytes' => 0, 'files' => 0, 'truncated' => false];
    }

    $bytes = 0;
    $files = 0;
    $truncated = false;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) continue;
            $files++;
            $size = $item->getSize();
            if ($size > 0) $bytes += $size;
            if ($files >= $maxFiles) {
                $truncated = true;
                break;
            }
        }
    } catch (Throwable $e) {
        return ['bytes' => $bytes, 'files' => $files, 'truncated' => true];
    }

    return ['bytes' => $bytes, 'files' => $files, 'truncated' => $truncated];
}

function sigoi_health_storage(PDO $pdo, string $projectRoot): array
{
    $cacheFile = $projectRoot . '/storage/system-health-cache.json';
    $cache = null;

    if (is_file($cacheFile)) {
        $mtime = @filemtime($cacheFile);
        if ($mtime !== false && $mtime >= time() - 300) {
            $raw = @file_get_contents($cacheFile);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) $cache = $decoded;
        }
    }

    if (is_array($cache)) return $cache;

    $dirs = [
        'whatsapp_media' => $projectRoot . '/storage/whatsapp',
        'whatsapp_queue' => $projectRoot . '/storage/whatsapp_queue',
        'instagram_media' => $projectRoot . '/storage/instagram_media',
        'instagram_queue' => $projectRoot . '/storage/instagram_queue',
        'messenger' => $projectRoot . '/storage/messenger',
    ];

    $result = [];
    foreach ($dirs as $key => $dir) {
        $result[$key] = sigoi_health_storage_scan($dir);
    }
    $result['generated_at'] = date('c');

    $storageRoot = $projectRoot . '/storage';
    if (is_dir($storageRoot) && is_writable($storageRoot)) {
        @file_put_contents(
            $cacheFile,
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        @chmod($cacheFile, 0640);
    }

    return $result;
}

function sigoi_health_last_activity(PDO $pdo, string $table): ?string
{
    if (!preg_match('/^[a-z0-9_]+$/i', $table)) return null;
    try {
        $stmt = $pdo->query("SELECT MAX(COALESCE(ultimo_mensaje_en, creado_en)) FROM `{$table}`");
        $value = $stmt ? $stmt->fetchColumn() : null;
        return $value ? (string)$value : null;
    } catch (Throwable $e) {
        return null;
    }
}

function sigoi_health_messenger_queue(PDO $pdo): array
{
    try {
        $row = $pdo->query("SELECT
            SUM(CASE WHEN estado IN ('pendiente','error') AND intentos < 5 THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN estado = 'procesando' THEN 1 ELSE 0 END) AS processing,
            SUM(CASE WHEN estado = 'error' AND intentos >= 5 THEN 1 ELSE 0 END) AS failed
            FROM messenger_eventos")->fetch(PDO::FETCH_ASSOC);

        return [
            'pending' => (int)($row['pending'] ?? 0),
            'processing' => (int)($row['processing'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
        ];
    } catch (Throwable $e) {
        return [
            'pending' => 0,
            'processing' => 0,
            'failed' => 0,
            'unavailable' => true,
        ];
    }
}

function sigoi_health_channel_status(bool $configured, array $cron, array $queue): string
{
    if (!$configured) return 'error';
    if (($cron['status'] ?? 'warning') === 'error') return 'error';
    if (($cron['status'] ?? 'warning') === 'warning') return 'warning';
    if (!empty($queue['failed']) || !empty($queue['unavailable'])) return 'warning';
    return 'ok';
}

try {
    global $pdo;
    $projectRoot = dirname(__DIR__);

    $dbOk = false;
    try {
        $dbOk = (bool)$pdo->query('SELECT 1')->fetchColumn();
    } catch (Throwable $e) {
        $dbOk = false;
    }

    $waRoot = $projectRoot . '/storage/whatsapp_queue';
    $igRoot = $projectRoot . '/storage/instagram_queue';
    $msgRoot = $projectRoot . '/storage/messenger';

    $waCron = sigoi_health_read_heartbeat($waRoot . '/cron-heartbeat.json');
    $igCron = sigoi_health_read_heartbeat($igRoot . '/cron-heartbeat.json');
    $msgCron = sigoi_health_read_heartbeat($msgRoot . '/cron-heartbeat.json');

    $waQueue = [
        'pending' => sigoi_health_file_count($waRoot . '/pending'),
        'processing' => sigoi_health_file_count($waRoot . '/processing'),
        'failed' => sigoi_health_file_count($waRoot . '/failed'),
    ];
    $igQueue = [
        'pending' => sigoi_health_file_count($igRoot . '/pending'),
        'processing' => sigoi_health_file_count($igRoot . '/processing'),
        'failed' => sigoi_health_file_count($igRoot . '/failed'),
    ];
    $msgQueue = sigoi_health_messenger_queue($pdo);

    $waConfigured = is_file($projectRoot . '/config/whatsapp_ycloud.php')
        && is_file($projectRoot . '/modules/whatsapp/webhook.php');
    $igConfigured = is_file($projectRoot . '/config/instagram_meta.php')
        && is_file($projectRoot . '/modules/instagram/webhook.php');
    $msgConfigured = is_file($projectRoot . '/config/messenger_meta.php')
        && is_file($projectRoot . '/modules/messenger/webhook.php');

    $channels = [
        'whatsapp' => [
            'name' => 'WhatsApp',
            'status' => sigoi_health_channel_status($waConfigured, $waCron, $waQueue),
            'webhook' => [
                'status' => $waConfigured ? 'ok' : 'error',
                'label' => $waConfigured ? 'Configurado' : 'Configuración incompleta',
            ],
            'cron' => $waCron,
            'queue' => $waQueue,
            'last_activity' => sigoi_health_last_activity($pdo, 'whatsapp_conversaciones'),
        ],
        'instagram' => [
            'name' => 'Instagram',
            'status' => sigoi_health_channel_status($igConfigured, $igCron, $igQueue),
            'webhook' => [
                'status' => $igConfigured ? 'ok' : 'error',
                'label' => $igConfigured ? 'Configurado' : 'Configuración incompleta',
            ],
            'cron' => $igCron,
            'queue' => $igQueue,
            'last_activity' => sigoi_health_last_activity($pdo, 'instagram_conversaciones'),
        ],
        'messenger' => [
            'name' => 'Messenger',
            'status' => sigoi_health_channel_status($msgConfigured, $msgCron, $msgQueue),
            'webhook' => [
                'status' => $msgConfigured ? 'ok' : 'error',
                'label' => $msgConfigured ? 'Configurado' : 'Configuración incompleta',
            ],
            'cron' => $msgCron,
            'queue' => $msgQueue,
            'last_activity' => sigoi_health_last_activity($pdo, 'messenger_conversaciones'),
        ],
    ];

    $storageHtaccess = $projectRoot . '/storage/.htaccess';
    $storageProtected = false;
    if (is_file($storageHtaccess)) {
        $contents = (string)@file_get_contents($storageHtaccess);
        $storageProtected = stripos($contents, 'Require all denied') !== false
            || stripos($contents, 'Deny from all') !== false;
    }

    $extensions = [];
    foreach (['pdo_mysql', 'curl', 'mbstring', 'openssl', 'fileinfo'] as $extension) {
        $extensions[$extension] = extension_loaded($extension);
    }

    $displayErrors = strtolower(trim((string)ini_get('display_errors')));
    $displayErrorsOn = in_array($displayErrors, ['1', 'on', 'yes', 'true'], true);

    $systemStatus = 'ok';
    if (!$dbOk) {
        $systemStatus = 'error';
    } else {
        foreach ($channels as $channel) {
            if ($channel['status'] === 'error') {
                $systemStatus = 'error';
                break;
            }
            if ($channel['status'] === 'warning' && $systemStatus === 'ok') {
                $systemStatus = 'warning';
            }
        }
    }

    echo json_encode([
        'success' => true,
        'generated_at' => date('c'),
        'system_status' => $systemStatus,
        'version' => defined('SIGOI_VERSION') ? SIGOI_VERSION : 'dev',
        'channels' => $channels,
        'platform' => [
            'database' => [
                'status' => $dbOk ? 'ok' : 'error',
                'label' => $dbOk ? 'Conectada' : 'Sin conexión',
            ],
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'memory_limit' => (string)ini_get('memory_limit'),
                'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
                'post_max_size' => (string)ini_get('post_max_size'),
            ],
            'extensions' => $extensions,
        ],
        'security' => [
            'storage_protected' => $storageProtected,
            'display_errors' => !$displayErrorsOn,
            'expose_php' => !filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN),
        ],
        'storage' => sigoi_health_storage($pdo, $projectRoot),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('System health: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo consultar el estado del sistema.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
