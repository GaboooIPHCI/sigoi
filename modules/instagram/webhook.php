<?php

declare(strict_types=1);

/**
 * Webhook público de Meta / Instagram para S.I.G.O.I.
 *
 * Fase 1:
 * - Verifica la URL con Meta mediante GET (hub.challenge).
 * - Valida la firma X-Hub-Signature-256 cuando APP_SECRET está configurado.
 * - Guarda eventos POST en una cola local para analizarlos/procesarlos después.
 * - Responde rápido para evitar reintentos innecesarios de Meta.
 */

require_once __DIR__ . '/../../config/instagram_meta.php';

function ig_webhook_text(string $text, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Length: ' . strlen($text));
    echo $text;
    exit;
}

function ig_webhook_json(array $payload, int $status = 200): void
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        $body = '{"ok":false,"error":"json_encode"}';
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

function ig_webhook_query(string $name): string
{
    // PHP normalmente convierte los puntos de hub.mode a guiones bajos.
    $underscore = str_replace('.', '_', $name);

    if (isset($_GET[$name])) {
        return trim((string)$_GET[$name]);
    }

    if (isset($_GET[$underscore])) {
        return trim((string)$_GET[$underscore]);
    }

    return '';
}

function ig_webhook_header(string $name): string
{
    $normalized = strtoupper(str_replace('-', '_', $name));
    $candidates = [
        'HTTP_' . $normalized,
        'REDIRECT_HTTP_' . $normalized,
        $normalized,
    ];

    foreach ($candidates as $key) {
        if (isset($_SERVER[$key]) && trim((string)$_SERVER[$key]) !== '') {
            return trim((string)$_SERVER[$key]);
        }
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strcasecmp((string)$key, $name) === 0) {
                    return trim((string)$value);
                }
            }
        }
    }

    return '';
}

function ig_webhook_verify_token_is_configured(): bool
{
    return defined('INSTAGRAM_META_VERIFY_TOKEN')
        && INSTAGRAM_META_VERIFY_TOKEN !== ''
        && strpos(INSTAGRAM_META_VERIFY_TOKEN, 'PEGA_AQUI_') !== 0;
}

function ig_webhook_app_secret_is_configured(): bool
{
    return defined('INSTAGRAM_META_APP_SECRET')
        && INSTAGRAM_META_APP_SECRET !== ''
        && strpos(INSTAGRAM_META_APP_SECRET, 'PEGA_AQUI_') !== 0;
}

function ig_webhook_signature_is_valid(string $raw): bool
{
    if (!ig_webhook_app_secret_is_configured()) {
        return false;
    }

    $signatureHeader = ig_webhook_header('X-Hub-Signature-256');
    if ($signatureHeader === '' || strpos($signatureHeader, 'sha256=') !== 0) {
        return false;
    }

    $received = substr($signatureHeader, 7);
    if ($received === '') {
        return false;
    }

    $expected = hash_hmac('sha256', $raw, (string)INSTAGRAM_META_APP_SECRET);
    return hash_equals($expected, $received);
}

function ig_webhook_queue_dir(): string
{
    return __DIR__ . '/../../storage/instagram_queue';
}

function ig_webhook_ensure_queue(): array
{
    $root = ig_webhook_queue_dir();
    $pending = $root . '/pending';
    $failed = $root . '/failed';

    foreach ([$root, $pending, $failed] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear la cola de Instagram.');
        }
    }

    $htaccess = $root . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }

    $index = $root . '/index.php';
    if (!is_file($index)) {
        @file_put_contents($index, "<?php\nhttp_response_code(404);\nexit;\n");
    }

    return [$root, $pending, $failed];
}

function ig_webhook_enqueue(string $raw): string
{
    [, $pending] = ig_webhook_ensure_queue();

    $decoded = json_decode($raw, true);
    $object = is_array($decoded) ? trim((string)($decoded['object'] ?? 'unknown')) : 'invalid';

    try {
        $random = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $random = str_replace('.', '', uniqid('', true));
    }

    $filename = sprintf(
        '%s_%s_%s.json',
        gmdate('Ymd_His'),
        preg_replace('/[^a-zA-Z0-9_-]+/', '-', $object) ?: 'unknown',
        $random
    );

    $path = $pending . '/' . $filename;
    $envelope = [
        'received_at' => gmdate('c'),
        'object' => $object,
        'payload' => $decoded,
        'raw' => $decoded === null ? $raw : null,
    ];

    $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar el evento de Instagram.');
    }

    return $filename;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// 1) Handshake de Meta para verificar la Callback URL.
if ($method === 'GET') {
    $mode = ig_webhook_query('hub.mode');
    $token = ig_webhook_query('hub.verify_token');
    $challenge = ig_webhook_query('hub.challenge');

    if ($mode !== '' || $token !== '' || $challenge !== '') {
        if (
            $mode === 'subscribe'
            && $challenge !== ''
            && ig_webhook_verify_token_is_configured()
            && hash_equals((string)INSTAGRAM_META_VERIFY_TOKEN, $token)
        ) {
            ig_webhook_text($challenge, 200);
        }

        ig_webhook_text('Forbidden', 403);
    }

    // Estado técnico seguro: no muestra tokens ni secretos.
    $queueCount = 0;
    try {
        [, $pending] = ig_webhook_ensure_queue();
        $files = glob($pending . '/*.json');
        $queueCount = is_array($files) ? count($files) : 0;
    } catch (Throwable $e) {
        // La verificación GET puede seguir funcionando aunque el storage aún falle.
    }

    ig_webhook_json([
        'ok' => true,
        'service' => 'instagram-meta-webhook',
        'phase' => '1-reception',
        'verification_ready' => ig_webhook_verify_token_is_configured(),
        'signature_ready' => ig_webhook_app_secret_is_configured(),
        'queued_events' => $queueCount,
    ]);
}

// 2) Eventos reales de Meta.
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        ig_webhook_json(['received' => false, 'error' => 'empty_body'], 400);
    }

    // En producción no aceptamos eventos si todavía no se configuró APP_SECRET.
    if (!ig_webhook_app_secret_is_configured()) {
        ig_webhook_json(['received' => false, 'error' => 'app_secret_not_configured'], 503);
    }

    if (!ig_webhook_signature_is_valid($raw)) {
        ig_webhook_json(['received' => false, 'error' => 'invalid_signature'], 403);
    }

    try {
        $queued = ig_webhook_enqueue($raw);
    } catch (Throwable $e) {
        ig_webhook_json(['received' => false, 'error' => 'queue_write_failed'], 500);
    }

    ig_webhook_json(['received' => true, 'queued' => $queued], 200);
}

header('Allow: GET, POST');
ig_webhook_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
