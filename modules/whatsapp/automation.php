<?php

declare(strict_types=1);

/**
 * Motor de WhatsApp/YCloud compartido por automatización, bandeja y webhook.
 * Debe funcionar sin sesión porque webhook.php también lo carga públicamente.
 */

function wa_auto_normalize(string $text): string
{
    $text = trim($text);
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = strtr($text, [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','ñ'=>'n',
    ]);
    $text = preg_replace('/[^a-z0-9\s]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function wa_auto_keywords(string $raw): array
{
    $parts = preg_split('/[,;\n\r]+/u', $raw) ?: [];
    $out = [];
    $seen = [];
    foreach ($parts as $part) {
        $display = trim((string)$part);
        $normalized = wa_auto_normalize($display);
        if ($normalized === '' || isset($seen[$normalized])) {
            continue;
        }
        $seen[$normalized] = true;
        $out[] = $display;
    }
    return $out;
}

function wa_auto_match_keyword(string $message, string $keywordsRaw): ?string
{
    $message = wa_auto_normalize($message);
    if ($message === '') {
        return null;
    }

    foreach (wa_auto_keywords($keywordsRaw) as $keyword) {
        $normalized = wa_auto_normalize($keyword);
        if ($normalized === '') {
            continue;
        }
        $pattern = '/(?<![a-z0-9])' . preg_quote($normalized, '/') . '(?![a-z0-9])/u';
        if (preg_match($pattern, $message)) {
            return $keyword;
        }
    }
    return null;
}

function wa_auto_find_rule(PDO $pdo, string $message): ?array
{
    $stmt = $pdo->query("SELECT id, nombre, palabras_clave, respuesta, prioridad
        FROM whatsapp_reglas
        WHERE activa = 1
        ORDER BY prioridad ASC, id ASC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
        $matched = wa_auto_match_keyword($message, (string)$rule['palabras_clave']);
        if ($matched !== null) {
            $rule['palabra_coincidente'] = $matched;
            return $rule;
        }
    }
    return null;
}

function wa_auto_settings(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT automatizacion_activa, mensaje_bienvenida, mensaje_no_reconocido, zona_horaria
        FROM whatsapp_configuracion WHERE id = 1 LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'automatizacion_activa' => (int)($row['automatizacion_activa'] ?? 0),
        'mensaje_bienvenida' => trim((string)($row['mensaje_bienvenida'] ?? '')),
        'mensaje_no_reconocido' => trim((string)($row['mensaje_no_reconocido'] ?? '')),
        'zona_horaria' => trim((string)($row['zona_horaria'] ?? 'America/Lima')) ?: 'America/Lima',
    ];
}

/** Devuelve true cuando NO hay atención humana en este instante. */
function wa_auto_is_outside_human_hours(PDO $pdo, string $timezone, ?DateTimeImmutable $at = null): bool
{
    try {
        $tz = new DateTimeZone($timezone);
    } catch (Throwable $e) {
        $tz = new DateTimeZone('America/Lima');
    }

    $now = $at ?: new DateTimeImmutable('now', $tz);
    if ($now->getTimezone()->getName() !== $tz->getName()) {
        $now = $now->setTimezone($tz);
    }

    $day = (int)$now->format('N');
    $current = $now->format('H:i:s');

    $stmt = $pdo->prepare("SELECT atencion_humana_activa, hora_inicio, hora_fin
        FROM whatsapp_horarios WHERE dia_semana = :dia LIMIT 1");
    $stmt->execute([':dia' => $day]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int)$row['atencion_humana_activa'] !== 1) {
        return true;
    }

    $start = (string)($row['hora_inicio'] ?? '');
    $end = (string)($row['hora_fin'] ?? '');
    if ($start === '' || $end === '') {
        return true;
    }

    if ($start < $end) {
        return !($current >= $start && $current < $end);
    }
    if ($start > $end) {
        return !(($current >= $start || $current < $end));
    }
    return true;
}

function wa_auto_should_prepend_welcome(PDO $pdo, int $conversationId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_mensajes
        WHERE conversacion_id = :id
          AND direccion = 'saliente'
          AND respuesta_automatica = 1
          AND creado_en >= DATE_SUB(NOW(), INTERVAL 12 HOUR)");
    $stmt->execute([':id' => $conversationId]);
    return (int)$stmt->fetchColumn() === 0;
}

function wa_auto_build_response(PDO $pdo, int $conversationId, string $message, array $settings): array
{
    $rule = wa_auto_find_rule($pdo, $message);
    $requiresHuman = false;

    if ($rule) {
        $body = trim((string)$rule['respuesta']);
        $ruleId = (int)$rule['id'];
    } else {
        $body = trim((string)$settings['mensaje_no_reconocido']);
        $ruleId = null;
        $requiresHuman = true;
    }

    if (wa_auto_should_prepend_welcome($pdo, $conversationId)) {
        $welcome = trim((string)$settings['mensaje_bienvenida']);
        if ($welcome !== '') {
            $body = $welcome . ($body !== '' ? "\n\n" . $body : '');
        }
    }

    if ($body === '') {
        $body = 'Gracias por escribirnos. Nuestro equipo continuará con tu consulta cuando retomemos el horario de atención.';
        $requiresHuman = true;
    }

    $body = function_exists('mb_substr') ? mb_substr($body, 0, 4000, 'UTF-8') : substr($body, 0, 4000);

    return [
        'body' => $body,
        'rule_id' => $ruleId,
        'requires_human' => $requiresHuman,
        'rule' => $rule,
    ];
}

function wa_auto_digits(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function wa_auto_e164(string $phone): string
{
    $digits = wa_auto_digits($phone);
    return $digits !== '' ? '+' . $digits : '';
}

function wa_auto_ycloud_is_configured(): bool
{
    return defined('WHATSAPP_YCLOUD_SEND_ENABLED')
        && WHATSAPP_YCLOUD_SEND_ENABLED === true
        && defined('WHATSAPP_YCLOUD_SENDER')
        && wa_auto_digits((string)WHATSAPP_YCLOUD_SENDER) !== ''
        && defined('WHATSAPP_YCLOUD_API_KEY')
        && WHATSAPP_YCLOUD_API_KEY !== ''
        && strpos((string)WHATSAPP_YCLOUD_API_KEY, 'PEGA_AQUI_') !== 0;
}

function wa_auto_external_id(string $prefix, int $conversationId): string
{
    try {
        $rand = bin2hex(random_bytes(5));
    } catch (Throwable $e) {
        $rand = substr(md5(uniqid('', true)), 0, 10);
    }
    return 'sigoi_' . preg_replace('/[^a-z0-9_]+/i', '_', $prefix) . '_' . $conversationId . '_' . time() . '_' . $rand;
}

/**
 * Envía un payload de WhatsApp por YCloud y devuelve ids útiles para el historial.
 */
function wa_auto_send_payload(array $payload, bool $direct = true, int $timeoutSeconds = 18): array
{
    if (!wa_auto_ycloud_is_configured()) {
        return ['ok' => false, 'error' => 'YCloud todavía no está configurado para envío'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP cURL no está disponible en el servidor'];
    }

    $url = $direct
        ? 'https://api.ycloud.com/v2/whatsapp/messages/sendDirectly'
        : 'https://api.ycloud.com/v2/whatsapp/messages';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(4, max(1, $timeoutSeconds)),
        CURLOPT_TIMEOUT => max(2, $timeoutSeconds),
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . WHATSAPP_YCLOUD_API_KEY,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => $curlError ?: 'Error cURL'];
    }

    $json = json_decode((string)$raw, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = 'Error de YCloud';
        if (is_array($json)) {
            $message = (string)($json['error']['message'] ?? $json['message'] ?? $json['error'] ?? $message);
        }
        return [
            'ok' => false,
            'error' => $message,
            'http_code' => $httpCode,
            'response' => is_array($json) ? $json : null,
        ];
    }

    $data = is_array($json) ? $json : [];
    $messageObject = isset($data['whatsappMessage']) && is_array($data['whatsappMessage']) ? $data['whatsappMessage'] : $data;

    $ycloudId = trim((string)($messageObject['id'] ?? $data['id'] ?? ''));
    $wamid = trim((string)($messageObject['wamid'] ?? $data['wamid'] ?? ''));
    $status = trim((string)($messageObject['status'] ?? $data['status'] ?? 'accepted'));

    return [
        'ok' => true,
        'ycloud_id' => $ycloudId !== '' ? $ycloudId : null,
        'wamid' => $wamid !== '' ? $wamid : null,
        'status' => $status !== '' ? $status : 'accepted',
        'response' => $data,
    ];
}

function wa_auto_send_text(string $to, string $body, ?string $externalId = null, int $timeoutSeconds = 18): array
{
    $from = wa_auto_e164((string)(defined('WHATSAPP_YCLOUD_SENDER') ? WHATSAPP_YCLOUD_SENDER : ''));
    $recipient = wa_auto_e164($to);
    if ($from === '' || $recipient === '') {
        return ['ok' => false, 'error' => 'Número emisor o destinatario inválido'];
    }

    $payload = [
        'from' => $from,
        'to' => $recipient,
        'type' => 'text',
        'text' => [
            'body' => $body,
            'preview_url' => true,
        ],
    ];
    if ($externalId) {
        $payload['externalId'] = $externalId;
    }

    return wa_auto_send_payload($payload, true, $timeoutSeconds);
}

/** Sube un archivo a YCloud y devuelve el media id. */
function wa_auto_upload_media(string $localFile, string $filename, string $mimeType): array
{
    if (!wa_auto_ycloud_is_configured()) {
        return ['ok' => false, 'error' => 'YCloud todavía no está configurado para envío'];
    }
    if (!function_exists('curl_init') || !class_exists('CURLFile')) {
        return ['ok' => false, 'error' => 'PHP cURL/CURLFile no está disponible en el servidor'];
    }
    if (!is_file($localFile)) {
        return ['ok' => false, 'error' => 'No se encontró el archivo temporal'];
    }

    $sender = wa_auto_e164((string)WHATSAPP_YCLOUD_SENDER);
    $url = 'https://api.ycloud.com/v2/whatsapp/media/' . rawurlencode($sender) . '/upload';
    $file = new CURLFile($localFile, $mimeType ?: 'application/octet-stream', $filename ?: basename($localFile));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . WHATSAPP_YCLOUD_API_KEY,
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => ['file' => $file],
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => $curlError ?: 'Error al subir el archivo a YCloud'];
    }

    $json = json_decode((string)$raw, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = 'YCloud rechazó el archivo';
        if (is_array($json)) {
            $message = (string)($json['error']['message'] ?? $json['message'] ?? $message);
        }
        return ['ok' => false, 'error' => $message, 'http_code' => $httpCode];
    }

    $data = is_array($json) ? $json : [];
    $id = trim((string)($data['id'] ?? $data['mediaId'] ?? $data['media_id'] ?? $data['media']['id'] ?? ''));
    if ($id === '') {
        return ['ok' => false, 'error' => 'YCloud subió el archivo pero no devolvió un media id reconocido'];
    }

    return ['ok' => true, 'media_id' => $id, 'response' => $data];
}

function wa_auto_send_media(string $to, string $type, string $mediaId, string $caption = '', string $filename = '', ?string $externalId = null): array
{
    $from = wa_auto_e164((string)(defined('WHATSAPP_YCLOUD_SENDER') ? WHATSAPP_YCLOUD_SENDER : ''));
    $recipient = wa_auto_e164($to);
    if ($from === '' || $recipient === '') {
        return ['ok' => false, 'error' => 'Número emisor o destinatario inválido'];
    }

    $allowed = ['image', 'video', 'audio', 'document'];
    if (!in_array($type, $allowed, true)) {
        return ['ok' => false, 'error' => 'Tipo de archivo no soportado'];
    }

    $media = ['id' => $mediaId];
    if ($caption !== '' && $type !== 'audio') {
        $media['caption'] = $caption;
    }
    if ($filename !== '' && $type === 'document') {
        $media['filename'] = $filename;
    }

    $payload = [
        'from' => $from,
        'to' => $recipient,
        'type' => $type,
        $type => $media,
    ];
    if ($externalId) {
        $payload['externalId'] = $externalId;
    }

    return wa_auto_send_payload($payload, true);
}

function wa_auto_store_outgoing(
    PDO $pdo,
    int $conversationId,
    string $body,
    ?int $ruleId,
    array $sendResult,
    string $origin = 'automatizacion',
    ?int $userId = null,
    array $media = [],
    ?string $externalId = null
): int {
    $isAuto = $origin === 'automatizacion' ? 1 : 0;
    $sendOk = (bool)($sendResult['ok'] ?? false);
    $type = (string)($media['type'] ?? 'text');
    $status = (string)($sendOk ? ($sendResult['status'] ?? 'accepted') : 'failed');

    $stmt = $pdo->prepare("INSERT INTO whatsapp_mensajes
        (conversacion_id, wamid, ycloud_id, direccion, origen, usuario_id, tipo, contenido,
         media_id, media_url, media_local_path, media_mime, media_filename, media_size,
         regla_id, respuesta_automatica, fuera_horario, external_id, estado_envio, error_envio)
        VALUES
        (:conversation_id, :wamid, :ycloud_id, 'saliente', :origen, :usuario_id, :tipo, :contenido,
         :media_id, :media_url, :media_local_path, :media_mime, :media_filename, :media_size,
         :regla_id, :auto, :fuera_horario, :external_id, :estado, :error)");
    $stmt->execute([
        ':conversation_id' => $conversationId,
        ':wamid' => $sendOk ? ($sendResult['wamid'] ?? null) : null,
        ':ycloud_id' => $sendOk ? ($sendResult['ycloud_id'] ?? null) : null,
        ':origen' => $origin,
        ':usuario_id' => $userId,
        ':tipo' => $type,
        ':contenido' => $body !== '' ? $body : null,
        ':media_id' => $media['media_id'] ?? null,
        ':media_url' => $media['media_url'] ?? null,
        ':media_local_path' => $media['local_path'] ?? null,
        ':media_mime' => $media['mime'] ?? null,
        ':media_filename' => $media['filename'] ?? null,
        ':media_size' => isset($media['size']) ? (int)$media['size'] : null,
        ':regla_id' => $ruleId,
        ':auto' => $isAuto,
        ':fuera_horario' => !empty($media['fuera_horario']) ? 1 : 0,
        ':external_id' => $externalId,
        ':estado' => $status,
        ':error' => $sendOk ? null : (string)($sendResult['error'] ?? 'Error de envío'),
    ]);

    $messageId = (int)$pdo->lastInsertId();

    if (!$sendOk) {
        $failed = $pdo->prepare("UPDATE whatsapp_mensajes SET fallido_en = NOW() WHERE id = :id");
        $failed->execute([':id' => $messageId]);
    }

    $preview = trim($body);
    if ($preview === '') {
        $preview = '[' . ucfirst($type) . ']';
    }
    $preview = function_exists('mb_substr') ? mb_substr($preview, 0, 250, 'UTF-8') : substr($preview, 0, 250);

    $isHuman = in_array($origin, ['sigoi', 'whatsapp_app'], true);
    $isAutoSuccess = $isAuto === 1 && $sendOk;
    $isHumanSuccess = $isHuman && $sendOk;
    $updateSql = "UPDATE whatsapp_conversaciones SET
        ultimo_mensaje_en = NOW(),
        ultimo_mensaje_direccion = 'saliente',
        ultimo_mensaje_tipo = :tipo,
        ultimo_mensaje_preview = :preview,
        ultimo_mensaje_origen = :origen";

    if ($isAutoSuccess) {
        $updateSql .= ", primera_respuesta_auto_en = COALESCE(primera_respuesta_auto_en, NOW())";
    }
    if ($isHumanSuccess) {
        $updateSql .= ", primera_respuesta_humana_en = COALESCE(primera_respuesta_humana_en, NOW()),
            ultima_respuesta_humana_en = NOW(),
            requiere_humano = 0";
    }
    $updateSql .= " WHERE id = :id";

    $update = $pdo->prepare($updateSql);
    $update->execute([
        ':tipo' => $type,
        ':preview' => $preview,
        ':origen' => $origin,
        ':id' => $conversationId,
    ]);

    return $messageId;
}
