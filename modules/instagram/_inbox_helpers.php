<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/instagram_meta.php';
require_once __DIR__ . '/../../config/instagram_schema.php';
require_once __DIR__ . '/queue-worker-lib.php';
require_once __DIR__ . '/_media_helpers.php';

auth_require_module_view('whatsapp');

function instagram_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function instagram_request(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    return is_array($json) ? $json : $_POST;
}

function instagram_clean_text($value, int $max = 10000): string
{
    $value = trim((string)($value ?? ''));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }
    return substr($value, 0, $max);
}

function ig_inbox_users(PDO $pdo): array
{
    return $pdo->query("SELECT id, nombre, usuario, rol
        FROM usuarios_sistema
        WHERE activo = 1
        ORDER BY CASE rol WHEN 'recepcion' THEN 1 WHEN 'marketing' THEN 2 WHEN 'admin' THEN 3 ELSE 4 END,
                 nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function ig_inbox_send_enabled(): bool
{
    return defined('INSTAGRAM_META_SEND_ENABLED') && INSTAGRAM_META_SEND_ENABLED === true;
}

function ig_inbox_send_window(PDO $pdo, int $conversationId): array
{
    $stmt = $pdo->prepare("SELECT MAX(creado_en) FROM instagram_mensajes
        WHERE conversacion_id = :id AND direccion = 'entrante'");
    $stmt->execute([':id' => $conversationId]);
    $lastInbound = $stmt->fetchColumn();

    if (!$lastInbound) {
        return ['active' => false, 'last_inbound' => null, 'expires_at' => null];
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
        return ['active' => false, 'last_inbound' => (string)$lastInbound, 'expires_at' => null];
    }
}

function ig_inbox_origin_label(array $row): string
{
    $origin = (string)($row['origen'] ?? '');
    if ($origin === 'instagram_app') return 'Instagram';
    if ($origin === 'sigoi') {
        $name = trim((string)($row['usuario_nombre'] ?? ''));
        return $name !== '' ? $name : 'S.I.G.O.I.';
    }
    if ($origin === 'automatizacion') return 'Automático';
    return 'Cliente';
}

function ig_inbox_normalize_type(string $type): string
{
    $type = strtolower(trim($type));
    $map = [
        'texto' => 'text',
        'imagen' => 'image',
        'video' => 'video',
        'audio' => 'audio',
        'archivo' => 'document',
        'compartido' => 'document',
        'historia' => 'image',
        'reel' => 'video',
    ];
    return $map[$type] ?? ($type !== '' ? $type : 'text');
}

function ig_inbox_message_payload(array $row): array
{
    $type = ig_inbox_normalize_type((string)($row['tipo'] ?? 'texto'));
    $mediaUrl = trim((string)($row['media_url'] ?? ''));
    $mediaPath = trim((string)($row['media_path'] ?? ''));
    $media = null;

    if ($mediaPath !== '') {
        $media = [
            'url' => 'modules/instagram/media.php?id=' . (int)$row['id'],
            'mime' => (string)($row['media_mime'] ?? ''),
            'filename' => (string)($row['media_filename'] ?? 'Contenido de Instagram'),
            'size' => isset($row['media_size']) && $row['media_size'] !== null ? (int)$row['media_size'] : null,
        ];
    } elseif ($mediaUrl !== '') {
        $media = [
            'url' => $mediaUrl,
            'mime' => (string)($row['media_mime'] ?? ''),
            'filename' => (string)($row['media_filename'] ?? 'Contenido de Instagram'),
            'size' => isset($row['media_size']) && $row['media_size'] !== null ? (int)$row['media_size'] : null,
        ];
    }

    return [
        'id' => (int)$row['id'],
        'mid' => (string)($row['mid'] ?? ''),
        'direccion' => (string)$row['direccion'],
        'origen' => (string)($row['origen'] ?? ($row['direccion'] === 'entrante' ? 'cliente' : 'sigoi')),
        'origen_label' => ig_inbox_origin_label($row),
        'usuario_id' => isset($row['usuario_id']) && $row['usuario_id'] !== null ? (int)$row['usuario_id'] : null,
        'tipo' => $type,
        'contenido' => (string)($row['contenido'] ?? ''),
        'estado_envio' => (string)($row['estado_envio'] ?? ''),
        'creado_en' => (string)($row['creado_en'] ?? ''),
        'leido_en' => $row['leido_en'] ?? null,
        'reaccion' => $row['reaccion'] ?? null,
        'reaccion_emoji' => $row['reaccion_emoji'] ?? null,
        'editado_veces' => (int)($row['editado_veces'] ?? 0),
        'media' => $media,
    ];
}

function ig_inbox_conversation_row(PDO $pdo, int $id): ?array
{
    instagram_ensure_schema($pdo);

    $stmt = $pdo->prepare("SELECT c.*,
        c.asignado_usuario_id AS asignado_a,
        c.username AS username_whatsapp,
        u.nombre AS asignado_nombre,
        u.usuario AS asignado_usuario,
        (SELECT COUNT(*) FROM instagram_mensajes m WHERE m.conversacion_id = c.id) AS total_mensajes,
        (SELECT COUNT(*) FROM instagram_mensajes m WHERE m.conversacion_id = c.id AND m.direccion = 'entrante') AS total_entrantes,
        (SELECT COUNT(*) FROM instagram_mensajes m WHERE m.conversacion_id = c.id AND m.direccion = 'saliente') AS total_salientes,
        (SELECT MIN(m.creado_en) FROM instagram_mensajes m WHERE m.conversacion_id = c.id AND m.direccion = 'saliente') AS primera_respuesta_humana_en
        FROM instagram_conversaciones c
        LEFT JOIN usuarios_sistema u ON u.id = c.asignado_usuario_id
        WHERE c.id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $row['canal'] = 'instagram';
    return $row;
}

function ig_meta_send_text(string $igsid, string $body): array
{
    if (!ig_meta_token_is_configured()) {
        return ['ok' => false, 'error' => 'El token de acceso de Instagram no está configurado.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'El servidor no tiene cURL disponible.'];
    }

    $url = 'https://graph.instagram.com/' . rawurlencode(ig_meta_api_version()) . '/me/messages';
    $payload = json_encode([
        'recipient' => ['id' => $igsid],
        'message' => ['text' => $body],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . (string)INSTAGRAM_META_ACCESS_TOKEN,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = is_string($raw) ? json_decode($raw, true) : null;
    if ($code >= 200 && $code < 300 && is_array($data)) {
        return [
            'ok' => true,
            'message_id' => trim((string)($data['message_id'] ?? '')),
            'recipient_id' => trim((string)($data['recipient_id'] ?? $igsid)),
            'raw' => $data,
        ];
    }

    $error = '';
    if (is_array($data) && is_array($data['error'] ?? null)) {
        $error = trim((string)($data['error']['message'] ?? ''));
    }
    if ($error === '') $error = $curlError !== '' ? $curlError : 'Meta no pudo enviar el mensaje de Instagram.';

    return ['ok' => false, 'error' => $error, 'http_code' => $code, 'raw' => $data];
}


function ig_meta_send_media(string $igsid, string $attachmentType, string $publicUrl): array
{
    if (!ig_meta_token_is_configured()) {
        return ['ok' => false, 'error' => 'El token de acceso de Instagram no está configurado.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'El servidor no tiene cURL disponible.'];
    }

    $attachmentType = strtolower(trim($attachmentType));
    if (!in_array($attachmentType, ['image', 'video', 'audio'], true)) {
        return ['ok' => false, 'error' => 'Instagram solo admite imágenes, video o audio en este envío.'];
    }

    $url = 'https://graph.instagram.com/' . rawurlencode(ig_meta_api_version()) . '/me/messages';
    $payload = json_encode([
        'recipient' => ['id' => $igsid],
        'message' => [
            'attachment' => [
                'type' => $attachmentType,
                'payload' => ['url' => $publicUrl],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . (string)INSTAGRAM_META_ACCESS_TOKEN,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = is_string($raw) ? json_decode($raw, true) : null;
    if ($code >= 200 && $code < 300 && is_array($data)) {
        return [
            'ok' => true,
            'message_id' => trim((string)($data['message_id'] ?? '')),
            'recipient_id' => trim((string)($data['recipient_id'] ?? $igsid)),
            'raw' => $data,
        ];
    }

    $error = '';
    if (is_array($data) && is_array($data['error'] ?? null)) {
        $error = trim((string)($data['error']['message'] ?? ''));
    }
    if ($error === '') $error = $curlError !== '' ? $curlError : 'Meta no pudo enviar el archivo de Instagram.';

    return ['ok' => false, 'error' => $error, 'http_code' => $code, 'raw' => $data];
}
