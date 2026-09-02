<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/messenger_schema.php';

$messengerPrivateConfig = __DIR__ . '/../../config/messenger_meta.php';

if (is_file($messengerPrivateConfig)) {
    require_once $messengerPrivateConfig;
}


function messenger_json(
    bool $success,
    string $message = '',
    array $data = [],
    int $status = 200
): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

function messenger_request(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);

    return is_array($json) ? $json : $_POST;
}

function messenger_clean_text($value, int $max = 10000): string
{
    $value = trim((string)($value ?? ''));

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }

    return substr($value, 0, $max);
}

function messenger_config_string(string $name): string
{
    if (!defined($name)) {
        return '';
    }

    return trim((string)constant($name));
}

function messenger_page_id(): string
{
    return messenger_config_string('MESSENGER_META_PAGE_ID');
}

function messenger_page_token(): string
{
    return messenger_config_string('MESSENGER_META_PAGE_ACCESS_TOKEN');
}

function messenger_app_secret(): string
{
    return messenger_config_string('MESSENGER_META_APP_SECRET');
}

function messenger_verify_token(): string
{
    return messenger_config_string('MESSENGER_META_VERIFY_TOKEN');
}

function messenger_api_version(): string
{
    $version = messenger_config_string('MESSENGER_META_API_VERSION');

    if ($version === '' || $version === 'vXX.X') {
        return '';
    }

    return ltrim($version, '/');
}

function messenger_public_base_url(): string
{
    return rtrim(
        messenger_config_string('MESSENGER_PUBLIC_BASE_URL'),
        '/'
    );
}

function messenger_send_enabled(): bool
{
    return defined('MESSENGER_META_SEND_ENABLED')
        && MESSENGER_META_SEND_ENABLED === true
        && messenger_page_id() !== ''
        && messenger_page_token() !== ''
        && messenger_api_version() !== '';
}

function messenger_receive_configured(): bool
{
    return messenger_page_id() !== ''
        && messenger_verify_token() !== ''
        && messenger_app_secret() !== '';
}

function messenger_require_channel_permission(PDO $pdo): void
{
    messenger_ensure_schema($pdo);

    if (!messenger_channel_permission_for_user(
        $pdo,
        (int)auth_user_id()
    )) {
        messenger_json(
            false,
            'No tienes acceso al canal Messenger.',
            [],
            403
        );
    }
}

function messenger_storage_root(): string
{
    return dirname(__DIR__, 2) . '/storage/messenger';
}

function messenger_storage_relative(string $absolute): string
{
    $projectRoot = str_replace('\\', '/', dirname(__DIR__, 2));
    $absolute = str_replace('\\', '/', $absolute);

    if (strpos($absolute, $projectRoot . '/') === 0) {
        return substr($absolute, strlen($projectRoot) + 1);
    }

    return $absolute;
}

function messenger_storage_absolute(string $relative): ?string
{
    $relative = str_replace('\\', '/', trim($relative));

    if (
        $relative === ''
        || strpos($relative, '..') !== false
        || strpos($relative, 'storage/messenger/') !== 0
    ) {
        return null;
    }

    return dirname(__DIR__, 2) . '/' . $relative;
}

function messenger_ensure_storage_dir(
    string $kind = 'incoming',
    ?DateTimeInterface $date = null
): string {
    $date = $date ?: new DateTimeImmutable('now');

    $kind = preg_replace('/[^a-z0-9_-]/i', '', $kind) ?: 'incoming';

    $dir = messenger_storage_root()
        . '/' . $kind
        . '/' . $date->format('Y')
        . '/' . $date->format('m');

    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    return $dir;
}

function messenger_safe_filename(string $filename, string $fallback = 'archivo'): string
{
    $filename = trim($filename);

    if ($filename === '') {
        return $fallback;
    }

    $filename = preg_replace('/[\x00-\x1F\x7F]+/u', '', $filename) ?? $filename;
    $filename = str_replace(['/', '\\'], '-', $filename);

    return messenger_clean_text($filename, 220);
}

function messenger_extension_from_mime(
    string $mime,
    string $filename = ''
): string {
    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?: '';

    if ($ext !== '' && strlen($ext) <= 8) {
        return $ext;
    }

    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
    ];

    return $map[strtolower(trim($mime))] ?? 'bin';
}

function messenger_detect_media_type(
    string $mime,
    string $filename = ''
): ?string {
    $mime = strtolower(trim($mime));

    if (strpos($mime, 'image/') === 0) {
        return 'image';
    }

    if (strpos($mime, 'video/') === 0) {
        return 'video';
    }

    if (strpos($mime, 'audio/') === 0) {
        return 'audio';
    }

    if (
        $mime === 'application/pdf'
        || $mime === 'text/plain'
        || strpos($mime, 'application/') === 0
    ) {
        return 'file';
    }

    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));

    if ($ext !== '') {
        return 'file';
    }

    return null;
}

function messenger_media_limit(string $type): int
{
    switch ($type) {
        case 'image':
            return 10 * 1024 * 1024;

        case 'audio':
        case 'video':
            return 25 * 1024 * 1024;

        case 'file':
            return 25 * 1024 * 1024;

        default:
            return 0;
    }
}

function messenger_graph_request(
    string $path,
    string $method = 'GET',
    ?array $payload = null,
    int $timeout = 20
): array {
    if (
        messenger_page_token() === ''
        || messenger_api_version() === ''
    ) {
        return [
            'ok' => false,
            'error' => 'Messenger todavía no tiene token/API configurados.',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'error' => 'El servidor no tiene cURL disponible.',
        ];
    }

    $url = 'https://graph.facebook.com/'
        . rawurlencode(messenger_api_version())
        . '/'
        . ltrim($path, '/');

    $separator = strpos($url, '?') === false ? '?' : '&';
    $url .= $separator . 'access_token=' . rawurlencode(messenger_page_token());

    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => max(5, $timeout),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
    ];

    $method = strtoupper($method);

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode(
            $payload ?: [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $data = is_string($raw)
        ? json_decode($raw, true)
        : null;

    if (
        $code >= 200
        && $code < 300
        && is_array($data)
    ) {
        return [
            'ok' => true,
            'data' => $data,
            'http_code' => $code,
        ];
    }

    $error = '';

    if (is_array($data) && is_array($data['error'] ?? null)) {
        $error = trim((string)($data['error']['message'] ?? ''));
    }

    if ($error === '') {
        $error = $curlError !== ''
            ? $curlError
            : 'Meta no pudo completar la solicitud de Messenger.';
    }

    return [
        'ok' => false,
        'error' => $error,
        'http_code' => $code,
        'data' => $data,
    ];
}

function messenger_send_text_api(string $psid, string $body): array
{
    if (!messenger_send_enabled()) {
        return [
            'ok' => false,
            'error' => 'El envío de Messenger todavía está desactivado.',
        ];
    }

    $result = messenger_graph_request(
        messenger_page_id() . '/messages',
        'POST',
        [
            'recipient' => [
                'id' => $psid,
            ],
            'messaging_type' => 'RESPONSE',
            'message' => [
                'text' => $body,
            ],
        ]
    );

    if (!($result['ok'] ?? false)) {
        return $result;
    }

    $data = (array)($result['data'] ?? []);

    return [
        'ok' => true,
        'message_id' => trim((string)($data['message_id'] ?? '')),
        'recipient_id' => trim((string)($data['recipient_id'] ?? $psid)),
        'raw' => $data,
    ];
}

function messenger_send_media_api(
    string $psid,
    string $type,
    string $publicUrl
): array {
    if (!messenger_send_enabled()) {
        return [
            'ok' => false,
            'error' => 'El envío de Messenger todavía está desactivado.',
        ];
    }

    if (!in_array($type, ['image', 'video', 'audio', 'file'], true)) {
        return [
            'ok' => false,
            'error' => 'Tipo de archivo no compatible con Messenger.',
        ];
    }

    $result = messenger_graph_request(
        messenger_page_id() . '/messages',
        'POST',
        [
            'recipient' => [
                'id' => $psid,
            ],
            'messaging_type' => 'RESPONSE',
            'message' => [
                'attachment' => [
                    'type' => $type,
                    'payload' => [
                        'url' => $publicUrl,
                        'is_reusable' => false,
                    ],
                ],
            ],
        ],
        35
    );

    if (!($result['ok'] ?? false)) {
        return $result;
    }

    $data = (array)($result['data'] ?? []);

    return [
        'ok' => true,
        'message_id' => trim((string)($data['message_id'] ?? '')),
        'recipient_id' => trim((string)($data['recipient_id'] ?? $psid)),
        'raw' => $data,
    ];
}

function messenger_send_window(PDO $pdo, int $conversationId): array
{
    $stmt = $pdo->prepare("
        SELECT MAX(creado_en)
        FROM messenger_mensajes
        WHERE conversacion_id = :id
          AND direccion = 'entrante'
    ");
    $stmt->execute([':id' => $conversationId]);

    $lastInbound = $stmt->fetchColumn();

    if (!$lastInbound) {
        return [
            'active' => false,
            'last_inbound' => null,
            'expires_at' => null,
        ];
    }

    try {
        $tz = new DateTimeZone('America/Lima');
        $last = new DateTimeImmutable((string)$lastInbound, $tz);
        $expires = $last->modify('+24 hours');
        $now = new DateTimeImmutable('now', $tz);

        return [
            'active' => $now < $expires,
            'last_inbound' => $last->format('Y-m-d H:i:s'),
            'expires_at' => $expires->format('Y-m-d H:i:s'),
        ];
    } catch (Throwable $e) {
        return [
            'active' => false,
            'last_inbound' => (string)$lastInbound,
            'expires_at' => null,
        ];
    }
}

function messenger_users(PDO $pdo): array
{
    return $pdo->query("
        SELECT id, nombre, usuario, rol
        FROM usuarios_sistema
        WHERE activo = 1
        ORDER BY
            CASE rol
                WHEN 'recepcion' THEN 1
                WHEN 'marketing' THEN 2
                WHEN 'admin' THEN 3
                ELSE 4
            END,
            nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function messenger_conversation_row(PDO $pdo, int $id): ?array
{
    messenger_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT
            c.*,
            c.asignado_usuario_id AS asignado_a,
            u.nombre AS asignado_nombre,
            u.usuario AS asignado_usuario,

            (
                SELECT COUNT(*)
                FROM messenger_mensajes m
                WHERE m.conversacion_id = c.id
            ) AS total_mensajes,

            (
                SELECT COUNT(*)
                FROM messenger_mensajes m
                WHERE m.conversacion_id = c.id
                  AND m.direccion = 'entrante'
            ) AS total_entrantes,

            (
                SELECT COUNT(*)
                FROM messenger_mensajes m
                WHERE m.conversacion_id = c.id
                  AND m.direccion = 'saliente'
            ) AS total_salientes,

            (
                SELECT MIN(m.creado_en)
                FROM messenger_mensajes m
                WHERE m.conversacion_id = c.id
                  AND m.direccion = 'saliente'
                  AND m.origen IN ('sigoi','messenger_app')
            ) AS primera_respuesta_humana_en

        FROM messenger_conversaciones c

        LEFT JOIN usuarios_sistema u
            ON u.id = c.asignado_usuario_id

        WHERE c.id = :id

        LIMIT 1
    ");

    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $row['canal'] = 'messenger';
    $row['username_whatsapp'] = null;
    $row['telefono'] = null;

    return $row;
}

function messenger_origin_label(array $row): string
{
    $origin = (string)($row['origen'] ?? '');

    if ($origin === 'messenger_app') {
        return 'Messenger';
    }

    if ($origin === 'sigoi') {
        $name = trim((string)($row['usuario_nombre'] ?? ''));

        return $name !== ''
            ? $name
            : 'S.I.G.O.I.';
    }

    if ($origin === 'automatizacion') {
        return 'Automático';
    }

    if ($origin === 'meta_sync') {
        return 'Meta / Página';
    }

    return 'Cliente';
}

function messenger_message_payload(array $row): array
{
    $media = null;
    $path = trim((string)($row['media_path'] ?? ''));
    $url = trim((string)($row['media_url'] ?? ''));

    if ($path !== '' || $url !== '') {
        $media = [
            'url' => $path !== ''
                ? 'modules/messenger/media.php?id=' . (int)$row['id']
                : $url,
            'mime' => (string)($row['media_mime'] ?? ''),
            'filename' => (string)($row['media_filename'] ?? 'Archivo de Messenger'),
            'size' => isset($row['media_size']) && $row['media_size'] !== null
                ? (int)$row['media_size']
                : null,
        ];
    }

    return [
        'id' => (int)$row['id'],
        'mid' => (string)($row['mid'] ?? ''),
        'direccion' => (string)$row['direccion'],
        'origen' => (string)($row['origen'] ?? 'cliente'),
        'origen_label' => messenger_origin_label($row),
        'usuario_id' => isset($row['usuario_id']) && $row['usuario_id'] !== null
            ? (int)$row['usuario_id']
            : null,
        'tipo' => (string)($row['tipo'] ?? 'text'),
        'contenido' => (string)($row['contenido'] ?? ''),
        'estado_envio' => (string)($row['estado_envio'] ?? ''),
        'creado_en' => (string)($row['creado_en'] ?? ''),
        'entregado_en' => $row['entregado_en'] ?? null,
        'leido_en' => $row['leido_en'] ?? null,
        'error_envio' => (string)($row['error_envio'] ?? ''),
        'media' => $media,
    ];
}

function messenger_profile(PDO $pdo, string $psid): ?array
{
    $result = messenger_graph_request(
        rawurlencode($psid)
        . '?fields=first_name,last_name,name,profile_pic',
        'GET',
        null,
        8
    );

    if (!($result['ok'] ?? false)) {
        return null;
    }

    $data = (array)($result['data'] ?? []);

    $name = trim((string)($data['name'] ?? ''));

    if ($name === '') {
        $name = trim(
            (string)($data['first_name'] ?? '')
            . ' '
            . (string)($data['last_name'] ?? '')
        );
    }

    return [
        'name' => $name !== '' ? $name : null,
        'profile_pic' => trim((string)($data['profile_pic'] ?? '')) ?: null,
    ];
}
