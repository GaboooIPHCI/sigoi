<?php
declare(strict_types=1);

/**
 * S.I.G.O.I. WhatsApp Webhook 5.0
 *
 * Arquitectura:
 * YCloud -> este archivo -> cola local -> HTTP 200 inmediato
 *
 * Este archivo NO abre MySQL y NO ejecuta reglas.
 * Eso evita los timeouts que YCloud estaba mostrando.
 */

require_once __DIR__ . '/../../config/whatsapp_ycloud.php';

function waq_json(array $payload, int $status = 200): void
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) $body = '{"ok":false}';

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

function waq_header(string $name): string
{
    $normalized = strtoupper(str_replace('-', '_', $name));
    foreach (['HTTP_' . $normalized, 'REDIRECT_HTTP_' . $normalized, $normalized] as $key) {
        if (isset($_SERVER[$key]) && trim((string)$_SERVER[$key]) !== '') {
            return trim((string)$_SERVER[$key]);
        }
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strcasecmp((string)$key, $name) === 0) return trim((string)$value);
            }
        }
    }
    return '';
}

function waq_secret_ready(): bool
{
    return defined('WHATSAPP_YCLOUD_WEBHOOK_SECRET')
        && trim((string)WHATSAPP_YCLOUD_WEBHOOK_SECRET) !== ''
        && strpos((string)WHATSAPP_YCLOUD_WEBHOOK_SECRET, 'PEGA_AQUI_') !== 0;
}

function waq_signature_valid(string $raw): bool
{
    if (!waq_secret_ready()) return false;

    $header = waq_header('YCloud-Signature');
    if ($header === '') return false;

    $timestamp = '';
    $signature = '';
    foreach (explode(',', $header) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2) continue;
        if (trim($pair[0]) === 't') $timestamp = trim($pair[1]);
        if (trim($pair[0]) === 's') $signature = trim($pair[1]);
    }

    if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) return false;
    if (abs(time() - (int)$timestamp) > 600) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $raw, (string)WHATSAPP_YCLOUD_WEBHOOK_SECRET);
    return hash_equals($expected, $signature);
}

function waq_root(): string
{
    return dirname(__DIR__, 2) . '/storage/whatsapp_queue';
}

function waq_dir(string $name): string
{
    $dir = waq_root() . '/' . $name;
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

function waq_safe_id(string $value): string
{
    $value = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $value) ?: '';
    return substr($value, 0, 140);
}

function waq_count(string $dir): int
{
    $files = glob(waq_dir($dir) . '/*.json');
    return is_array($files) ? count($files) : 0;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    waq_json([
        'ok' => true,
        'servicio' => 'S.I.G.O.I. WhatsApp Webhook',
        'proveedor' => 'YCloud Coexistence',
        'sync_fix' => '5.0-queue',
        'modo' => 'ack_inmediato_con_cola',
        'firma_ycloud_configurada' => waq_secret_ready(),
        'cola_pendiente' => waq_count('pending'),
        'cola_procesando' => waq_count('processing'),
        'cola_fallida' => waq_count('failed'),
    ]);
}

if ($method !== 'POST') {
    waq_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$raw = file_get_contents('php://input');
$raw = $raw === false ? '' : $raw;

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    waq_json(['received' => true, 'queued' => false, 'ignored' => 'invalid_json']);
}

if (!waq_signature_valid($raw)) {
    waq_json(['ok' => false, 'error' => 'Firma YCloud inválida'], 401);
}

$eventId = trim((string)($payload['id'] ?? ''));
$eventType = trim((string)($payload['type'] ?? ''));

if ($eventId === '') {
    $eventId = 'evt_local_' . sha1($raw);
}

$safeId = waq_safe_id($eventId);
$pending = waq_dir('pending');
$processing = waq_dir('processing');
$failed = waq_dir('failed');

$final = $pending . '/' . $safeId . '.json';
$alreadyQueued = is_file($final)
    || is_file($processing . '/' . $safeId . '.json')
    || is_file($failed . '/' . $safeId . '.json');

if (!$alreadyQueued) {
    $wrapper = [
        'event_id' => $eventId,
        'event_type' => $eventType,
        'queued_at' => date('c'),
        'attempts' => 0,
        'payload' => $payload,
    ];

    $encoded = json_encode($wrapper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        waq_json(['received' => true, 'queued' => false, 'error' => 'queue_encode']);
    }

    $tmp = $pending . '/.' . $safeId . '.' . uniqid('', true) . '.tmp';
    $written = @file_put_contents($tmp, $encoded, LOCK_EX);
    if ($written === false || !@rename($tmp, $final)) {
        @unlink($tmp);
        waq_json(['received' => false, 'queued' => false, 'error' => 'queue_write'], 500);
    }
}

waq_json([
    'received' => true,
    'queued' => true,
    'duplicate' => $alreadyQueued,
]);
