<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../config/whatsapp_ycloud.php';
require_once __DIR__ . '/automation.php';

function wa_inbox_storage_root(): string
{
    return dirname(__DIR__, 2) . '/storage/whatsapp';
}

function wa_inbox_relative_storage_path(string $absolute): string
{
    $projectRoot = str_replace('\\', '/', dirname(__DIR__, 2));
    $absolute = str_replace('\\', '/', $absolute);
    if (strpos($absolute, $projectRoot . '/') === 0) {
        return substr($absolute, strlen($projectRoot) + 1);
    }
    return $absolute;
}

function wa_inbox_storage_absolute(string $relative): ?string
{
    $relative = str_replace('\\', '/', trim($relative));
    if ($relative === '' || strpos($relative, '..') !== false) {
        return null;
    }
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $path = $root . '/' . ltrim($relative, '/');
    return $path;
}

function wa_inbox_ensure_storage_dir(?DateTimeInterface $date = null): string
{
    $date = $date ?: new DateTimeImmutable('now');
    $dir = wa_inbox_storage_root() . '/' . $date->format('Y') . '/' . $date->format('m');
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

function wa_inbox_extension_from_mime(string $mime, string $filename = ''): string
{
    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?: '';
    if ($ext !== '' && strlen($ext) <= 8) {
        return $ext;
    }

    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'video/mp4' => 'mp4',
        'video/3gpp' => '3gp',
        'audio/aac' => 'aac',
        'audio/mp4' => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/amr' => 'amr',
        'audio/ogg' => 'ogg',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];
    return $map[strtolower(trim($mime))] ?? 'bin';
}

function wa_inbox_detect_media_type(string $mime, string $filename = ''): ?string
{
    $mime = strtolower(trim($mime));
    if ($mime === 'image/jpeg' || $mime === 'image/png') {
        return 'image';
    }
    if ($mime === 'video/mp4' || $mime === 'video/3gpp') {
        return 'video';
    }
    if (in_array($mime, ['audio/aac','audio/mp4','audio/mpeg','audio/amr','audio/ogg'], true)) {
        return 'audio';
    }

    $documents = [
        'text/plain',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    if (in_array($mime, $documents, true)) {
        return 'document';
    }

    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt'], true)) {
        return 'document';
    }
    return null;
}

function wa_inbox_media_limit(string $type): int
{
    switch ($type) {
        case 'image': return 5 * 1024 * 1024;
        case 'video':
        case 'audio': return 16 * 1024 * 1024;
        case 'document': return 100 * 1024 * 1024;
        default: return 0;
    }
}

function wa_inbox_store_uploaded_file(string $tmp, string $filename, string $mime, int $size): array
{
    $type = wa_inbox_detect_media_type($mime, $filename);
    if ($type === null) {
        throw new RuntimeException('El tipo de archivo no está permitido en esta versión.');
    }

    $limit = wa_inbox_media_limit($type);
    if ($size <= 0 || ($limit > 0 && $size > $limit)) {
        throw new RuntimeException('El archivo supera el límite permitido para WhatsApp.');
    }

    $dir = wa_inbox_ensure_storage_dir();
    $ext = wa_inbox_extension_from_mime($mime, $filename);
    try {
        $random = bin2hex(random_bytes(10));
    } catch (Throwable $e) {
        $random = md5(uniqid('', true));
    }
    $target = $dir . '/' . date('Ymd_His') . '_' . $random . '.' . $ext;

    if (!@move_uploaded_file($tmp, $target)) {
        if (!@copy($tmp, $target)) {
            throw new RuntimeException('No se pudo guardar el archivo en el servidor.');
        }
    }
    @chmod($target, 0640);

    return [
        'type' => $type,
        'local_path' => wa_inbox_relative_storage_path($target),
        'absolute_path' => $target,
        'filename' => $filename,
        'mime' => $mime,
        'size' => $size,
    ];
}

function wa_inbox_send_window(PDO $pdo, int $conversationId): array
{
    $stmt = $pdo->prepare("SELECT MAX(creado_en) FROM whatsapp_mensajes
        WHERE conversacion_id = :id AND direccion = 'entrante'");
    $stmt->execute([':id' => $conversationId]);
    $lastInbound = $stmt->fetchColumn();

    if (!$lastInbound) {
        return ['active' => false, 'last_inbound' => null, 'expires_at' => null];
    }

    try {
        $last = new DateTimeImmutable((string)$lastInbound, new DateTimeZone('America/Lima'));
        $expires = $last->modify('+24 hours');
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
        return [
            'active' => $now < $expires,
            'last_inbound' => $last->format('Y-m-d H:i:s'),
            'expires_at' => $expires->format('Y-m-d H:i:s'),
        ];
    } catch (Throwable $e) {
        return ['active' => false, 'last_inbound' => (string)$lastInbound, 'expires_at' => null];
    }
}

function wa_inbox_users(PDO $pdo): array
{
    return $pdo->query("SELECT id, nombre, usuario, rol
        FROM usuarios_sistema
        WHERE activo = 1
        ORDER BY CASE rol WHEN 'recepcion' THEN 1 WHEN 'marketing' THEN 2 WHEN 'admin' THEN 3 ELSE 4 END,
                 nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function wa_inbox_origin_label(array $row): string
{
    $origin = (string)($row['origen'] ?? '');
    if ($origin === 'automatizacion') return 'Automático';
    if ($origin === 'whatsapp_app') return 'WhatsApp Business';
    if ($origin === 'ycloud') return 'YCloud';
    if ($origin === 'sigoi') {
        $name = trim((string)($row['usuario_nombre'] ?? ''));
        return $name !== '' ? $name : 'S.I.G.O.I.';
    }
    if ($origin === 'historial') return 'Historial';
    return 'Cliente';
}

function wa_inbox_message_payload(array $row): array
{
    $type = (string)($row['tipo'] ?? 'text');
    $hasMedia = !empty($row['media_local_path']) || !empty($row['media_url']) || !empty($row['media_id']);

    return [
        'id' => (int)$row['id'],
        'wamid' => $row['wamid'] ?? null,
        'direccion' => (string)$row['direccion'],
        'origen' => (string)($row['origen'] ?? ($row['direccion'] === 'entrante' ? 'cliente' : 'sigoi')),
        'origen_label' => wa_inbox_origin_label($row),
        'usuario_id' => isset($row['usuario_id']) ? (int)$row['usuario_id'] : null,
        'tipo' => $type,
        'contenido' => (string)($row['contenido'] ?? ''),
        'respuesta_automatica' => (int)($row['respuesta_automatica'] ?? 0) === 1,
        'fuera_horario' => (int)($row['fuera_horario'] ?? 0) === 1,
        'estado_envio' => (string)($row['estado_envio'] ?? ''),
        'error_envio' => (string)($row['error_envio'] ?? ''),
        'creado_en' => (string)($row['creado_en'] ?? ''),
        'enviado_en' => $row['enviado_en'] ?? null,
        'entregado_en' => $row['entregado_en'] ?? null,
        'leido_en' => $row['leido_en'] ?? null,
        'fallido_en' => $row['fallido_en'] ?? null,
        'media' => $hasMedia ? [
            'url' => 'modules/whatsapp/media.php?id=' . (int)$row['id'],
            'mime' => (string)($row['media_mime'] ?? ''),
            'filename' => (string)($row['media_filename'] ?? ''),
            'size' => isset($row['media_size']) ? (int)$row['media_size'] : null,
        ] : null,
    ];
}

function wa_inbox_conversation_row(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT c.*,
        u.nombre AS asignado_nombre,
        u.usuario AS asignado_usuario,
        (SELECT COUNT(*) FROM whatsapp_mensajes m WHERE m.conversacion_id = c.id) AS total_mensajes,
        (SELECT COUNT(*) FROM whatsapp_mensajes m WHERE m.conversacion_id = c.id AND m.direccion = 'entrante') AS total_entrantes,
        (SELECT COUNT(*) FROM whatsapp_mensajes m WHERE m.conversacion_id = c.id AND m.direccion = 'saliente') AS total_salientes
        FROM whatsapp_conversaciones c
        LEFT JOIN usuarios_sistema u ON u.id = c.asignado_a
        WHERE c.id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
