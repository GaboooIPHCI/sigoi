<?php

declare(strict_types=1);

/**
 * Webhook público YCloud / WhatsApp Coexistence.
 *
 * Eventos soportados:
 * - whatsapp.inbound_message.received   Mensajes del cliente.
 * - whatsapp.message.updated            Estados sent/delivered/read/failed.
 * - whatsapp.smb.message.echoes         Mensajes enviados desde WhatsApp Business App.
 * - whatsapp.smb.history                Historial sincronizado al activar Coexistence.
 */

$metaConfig = __DIR__ . '/../../config/whatsapp_meta.php';
if (is_file($metaConfig)) {
    require_once $metaConfig;
}
require_once __DIR__ . '/../../config/whatsapp_ycloud.php';
require_once __DIR__ . '/automation.php';

function wa_webhook_json(array $payload, int $status = 200): void
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

function wa_webhook_text(string $text, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Length: ' . strlen($text));
    echo $text;
    exit;
}

function wa_webhook_header(string $name): string
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
                if (strcasecmp((string)$key, $name) === 0) {
                    return trim((string)$value);
                }
            }
        }
    }
    return '';
}

function wa_webhook_ycloud_secret_is_configured(): bool
{
    return defined('WHATSAPP_YCLOUD_WEBHOOK_SECRET')
        && WHATSAPP_YCLOUD_WEBHOOK_SECRET !== ''
        && strpos((string)WHATSAPP_YCLOUD_WEBHOOK_SECRET, 'PEGA_AQUI_') !== 0;
}

function wa_webhook_ycloud_signature_is_valid(string $raw): bool
{
    $header = wa_webhook_header('YCloud-Signature');
    if ($header === '' || !wa_webhook_ycloud_secret_is_configured()) {
        return false;
    }

    $timestamp = '';
    $signature = '';
    foreach (explode(',', $header) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2) continue;
        if (trim($pair[0]) === 't') $timestamp = trim($pair[1]);
        if (trim($pair[0]) === 's') $signature = trim($pair[1]);
    }

    if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
        return false;
    }
    if (abs(time() - (int)$timestamp) > 600) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $raw, (string)WHATSAPP_YCLOUD_WEBHOOK_SECRET);
    return hash_equals($expected, $signature);
}

/** Responde 200 antes de procesar BD/archivos para evitar reintentos de YCloud. */
function wa_webhook_ack_ycloud(): void
{
    $body = '{"received":true}';
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
    ignore_user_abort(true);
}

function wa_webhook_sql_datetime(?string $iso): string
{
    $iso = trim((string)$iso);
    if ($iso === '') return date('Y-m-d H:i:s');
    try {
        $dt = new DateTimeImmutable($iso);
        return $dt->setTimezone(new DateTimeZone('America/Lima'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return date('Y-m-d H:i:s');
    }
}

function wa_webhook_datetime_object(?string $iso): ?DateTimeImmutable
{
    try {
        return (new DateTimeImmutable((string)$iso))->setTimezone(new DateTimeZone('America/Lima'));
    } catch (Throwable $e) {
        return null;
    }
}

function wa_webhook_preview(string $content, string $type): string
{
    $preview = trim($content);
    if ($preview === '') $preview = '[' . ucfirst($type ?: 'mensaje') . ']';
    return function_exists('mb_substr') ? mb_substr($preview, 0, 250, 'UTF-8') : substr($preview, 0, 250);
}

function wa_webhook_extract_content_media(array $message): array
{
    $type = trim((string)($message['type'] ?? 'unknown')) ?: 'unknown';
    $content = '';
    $media = [
        'id' => null,
        'url' => null,
        'mime' => null,
        'filename' => null,
        'size' => null,
    ];

    if ($type === 'text') {
        $content = trim((string)($message['text']['body'] ?? ''));
    } elseif ($type === 'button') {
        $content = trim((string)($message['button']['text'] ?? $message['button']['payload'] ?? ''));
    } elseif ($type === 'interactive') {
        $interactiveType = (string)($message['interactive']['type'] ?? '');
        if ($interactiveType === 'button_reply') {
            $content = trim((string)($message['interactive']['button_reply']['title'] ?? ''));
        } elseif ($interactiveType === 'list_reply') {
            $content = trim((string)($message['interactive']['list_reply']['title'] ?? ''));
        } else {
            $content = '[Mensaje interactivo]';
        }
    } elseif (in_array($type, ['image','video','audio','document','sticker'], true)) {
        $obj = isset($message[$type]) && is_array($message[$type]) ? $message[$type] : [];
        $caption = trim((string)($obj['caption'] ?? ''));
        $filename = trim((string)($obj['filename'] ?? ''));
        $labels = ['image'=>'Imagen','video'=>'Video','audio'=>'Audio','document'=>'Documento','sticker'=>'Sticker'];
        $content = '[' . $labels[$type] . ']';
        if ($filename !== '') $content .= ' ' . $filename;
        if ($caption !== '') $content .= ($filename !== '' ? ' - ' : ' ') . $caption;
        $media = [
            'id' => trim((string)($obj['id'] ?? '')) ?: null,
            'url' => trim((string)($obj['link'] ?? '')) ?: null,
            'mime' => trim((string)($obj['mime_type'] ?? '')) ?: null,
            'filename' => $filename !== '' ? $filename : null,
            'size' => isset($obj['file_size']) ? (int)$obj['file_size'] : null,
        ];
    } elseif ($type === 'location') {
        $lat = $message['location']['latitude'] ?? null;
        $lng = $message['location']['longitude'] ?? null;
        $name = trim((string)($message['location']['name'] ?? ''));
        $content = '[Ubicación]' . ($name !== '' ? ' ' . $name : '');
        if ($lat !== null && $lng !== null) $content .= ' ' . $lat . ', ' . $lng;
    } elseif ($type === 'contacts') {
        $content = '[Contacto compartido]';
    } elseif ($type === 'reaction') {
        $content = '[Reacción] ' . trim((string)($message['reaction']['emoji'] ?? ''));
    } else {
        $content = '[Mensaje de tipo ' . $type . ']';
    }

    return [$type, $content, $media];
}

function wa_webhook_media_extension(string $mime, string $filename): string
{
    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?: '';
    if ($ext !== '' && strlen($ext) <= 8) return $ext;
    $map = [
        'image/jpeg'=>'jpg','image/png'=>'png','video/mp4'=>'mp4','video/3gpp'=>'3gp',
        'audio/aac'=>'aac','audio/mp4'=>'m4a','audio/mpeg'=>'mp3','audio/amr'=>'amr','audio/ogg'=>'ogg',
        'application/pdf'=>'pdf','text/plain'=>'txt','application/msword'=>'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
        'application/vnd.ms-excel'=>'xls','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
        'application/vnd.ms-powerpoint'=>'ppt','application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx',
    ];
    return $map[strtolower($mime)] ?? 'bin';
}

function wa_webhook_download_media(?string $url, ?string $mime, ?string $filename, string $key): ?array
{
    $url = trim((string)$url);
    if ($url === '' || !function_exists('curl_init') || !defined('WHATSAPP_YCLOUD_API_KEY')) return null;

    $root = dirname(__DIR__, 2) . '/storage/whatsapp';
    $dir = $root . '/' . date('Y') . '/' . date('m');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return null;

    $ext = wa_webhook_media_extension((string)$mime, (string)$filename);
    $safe = date('Ymd_His') . '_' . substr(hash('sha256', $key . $url), 0, 22) . '.' . $ext;
    $target = $dir . '/' . $safe;

    $fp = @fopen($target, 'wb');
    if (!$fp) return null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['X-API-Key: ' . WHATSAPP_YCLOUD_API_KEY],
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $remoteMime = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $code < 200 || $code >= 300 || !is_file($target) || filesize($target) === 0) {
        @unlink($target);
        return null;
    }
    @chmod($target, 0640);

    $projectRoot = str_replace('\\', '/', dirname(__DIR__, 2));
    $relative = substr(str_replace('\\', '/', $target), strlen($projectRoot) + 1);
    return [
        'local_path' => $relative,
        'size' => filesize($target),
        'mime' => trim(explode(';', $remoteMime)[0]) ?: $mime,
    ];
}

function wa_webhook_event_seen(PDO $pdo, string $eventId, string $type): bool
{
    if ($eventId === '') return false;
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO whatsapp_eventos (event_id, tipo, procesado) VALUES (:id, :tipo, 0)");
        $stmt->execute([':id' => $eventId, ':tipo' => $type]);
        if ($stmt->rowCount() > 0) {
            return false;
        }

        // Solo ignoramos eventos que ya terminaron correctamente. Si un intento
        // anterior falló (procesado=0), permitimos que el reintento de YCloud lo procese.
        $check = $pdo->prepare("SELECT procesado FROM whatsapp_eventos WHERE event_id = :id LIMIT 1");
        $check->execute([':id' => $eventId]);
        return (int)$check->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function wa_webhook_event_done(PDO $pdo, string $eventId, ?string $error = null): void
{
    if ($eventId === '') return;
    try {
        $stmt = $pdo->prepare("UPDATE whatsapp_eventos SET procesado = :ok, error = :error WHERE event_id = :id");
        $stmt->execute([':ok' => $error === null ? 1 : 0, ':error' => $error, ':id' => $eventId]);
    } catch (Throwable $e) {
        // No bloquear el webhook por auditoría.
    }
}

function wa_webhook_store_inbound(PDO $pdo, array $message, string $eventId, bool $history = false): ?array
{
    $from = wa_auto_digits((string)($message['from'] ?? ''));
    $to = wa_auto_digits((string)($message['to'] ?? ''));
    $expected = wa_auto_digits((string)WHATSAPP_YCLOUD_SENDER);
    if ($from === '' || $to === '' || $to !== $expected) return null;

    $wamid = trim((string)($message['wamid'] ?? ''));
    $ycloudId = trim((string)($message['id'] ?? ''));
    if ($wamid === '') $wamid = $ycloudId !== '' ? 'ycloud:' . $ycloudId : ('ycloud-event:' . $eventId);

    $exists = $pdo->prepare("SELECT id FROM whatsapp_mensajes WHERE wamid = :wamid LIMIT 1");
    $exists->execute([':wamid' => $wamid]);
    if ($exists->fetchColumn()) return null;

    list($type, $content, $media) = wa_webhook_extract_content_media($message);
    $sentAt = wa_webhook_sql_datetime($message['sendTime'] ?? null);
    $at = wa_webhook_datetime_object($message['sendTime'] ?? null);
    $settings = wa_auto_settings($pdo);
    $outside = $history ? 0 : (wa_auto_is_outside_human_hours($pdo, (string)$settings['zona_horaria'], $at) ? 1 : 0);

    // Multimedia: guardar URL/metadata y descargar bajo demanda desde media.php.
    // Evita bloquear el webhook esperando archivos externos.

    $name = trim((string)($message['customerProfile']['name'] ?? ''));
    $username = trim((string)($message['customerProfile']['username'] ?? ''));
    $bsuid = trim((string)($message['fromUserId'] ?? $message['fromParentUserId'] ?? ''));

    $referral = is_array($message['referral'] ?? null) ? $message['referral'] : [];
    $originSource = trim((string)($referral['source_type'] ?? ''));
    if ($originSource === 'ad') {
        $originSource = 'meta_ad';
    }
    $originId = trim((string)($referral['source_id'] ?? ''));
    $originUrl = trim((string)($referral['source_url'] ?? ''));
    $originTitle = trim((string)($referral['headline'] ?? ''));
    $originMediaUrl = trim((string)($referral['image_url'] ?? $referral['video_url'] ?? ''));
    $originCtwa = trim((string)($referral['ctwa_clid'] ?? ''));

    $preview = wa_webhook_preview($content, $type);

    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare("SELECT id, estado FROM whatsapp_conversaciones WHERE telefono = :telefono LIMIT 1 FOR UPDATE");
        $find->execute([':telefono' => $from]);
        $conv = $find->fetch(PDO::FETCH_ASSOC);

        if (!$conv) {
            $insert = $pdo->prepare("INSERT INTO whatsapp_conversaciones
                (telefono, nombre_contacto, bsuid, username_whatsapp, estado, requiere_humano, no_leidos,
                 primer_mensaje_en, ultimo_mensaje_en, ultimo_mensaje_direccion, ultimo_mensaje_tipo,
                 ultimo_mensaje_preview, ultimo_mensaje_origen, origen_fuente, origen_id, origen_url,
                 origen_titulo, origen_media_url, origen_ctwa_clid)
                VALUES (:telefono, :nombre, :bsuid, :username, 'abierta', 0, :unread,
                        :first_at, :last_at, 'entrante', :tipo, :preview, :origen, :origen_fuente,
                        :origen_id, :origen_url, :origen_titulo, :origen_media_url, :origen_ctwa)");
            $insert->execute([
                ':telefono'=>$from, ':nombre'=>$name !== '' ? $name : null, ':bsuid'=>$bsuid !== '' ? $bsuid : null,
                ':username'=>$username !== '' ? $username : null, ':unread'=>$history ? 0 : 1,
                ':first_at'=>$sentAt, ':last_at'=>$sentAt, ':tipo'=>$type, ':preview'=>$preview,
                ':origen'=>$history ? 'historial' : 'cliente',
                ':origen_fuente'=>$originSource !== '' ? $originSource : null,
                ':origen_id'=>$originId !== '' ? $originId : null,
                ':origen_url'=>$originUrl !== '' ? $originUrl : null,
                ':origen_titulo'=>$originTitle !== '' ? $originTitle : null,
                ':origen_media_url'=>$originMediaUrl !== '' ? $originMediaUrl : null,
                ':origen_ctwa'=>$originCtwa !== '' ? $originCtwa : null,
            ]);
            $conversationId = (int)$pdo->lastInsertId();
        } else {
            $conversationId = (int)$conv['id'];
            $update = $pdo->prepare("UPDATE whatsapp_conversaciones SET
                nombre_contacto = COALESCE(NULLIF(:nombre,''), nombre_contacto),
                bsuid = COALESCE(NULLIF(:bsuid,''), bsuid),
                username_whatsapp = COALESCE(NULLIF(:username,''), username_whatsapp),
                origen_fuente = COALESCE(NULLIF(:origen_fuente,''), origen_fuente),
                origen_id = COALESCE(NULLIF(:origen_id,''), origen_id),
                origen_url = COALESCE(NULLIF(:origen_url,''), origen_url),
                origen_titulo = COALESCE(NULLIF(:origen_titulo,''), origen_titulo),
                origen_media_url = COALESCE(NULLIF(:origen_media_url,''), origen_media_url),
                origen_ctwa_clid = COALESCE(NULLIF(:origen_ctwa,''), origen_ctwa_clid),
                estado = CASE WHEN :history_estado = 1 THEN estado ELSE 'abierta' END,
                resuelto_en = CASE WHEN :history_resuelto = 1 THEN resuelto_en ELSE NULL END,
                no_leidos = no_leidos + :unread,
                primer_mensaje_en = COALESCE(primer_mensaje_en, :first_at),
                ultimo_mensaje_en = CASE WHEN ultimo_mensaje_en IS NULL OR :last_at_cmp >= ultimo_mensaje_en THEN :last_at_set ELSE ultimo_mensaje_en END,
                ultimo_mensaje_direccion = CASE WHEN ultimo_mensaje_en IS NULL OR :last_at2 >= ultimo_mensaje_en THEN 'entrante' ELSE ultimo_mensaje_direccion END,
                ultimo_mensaje_tipo = CASE WHEN ultimo_mensaje_en IS NULL OR :last_at3 >= ultimo_mensaje_en THEN :tipo ELSE ultimo_mensaje_tipo END,
                ultimo_mensaje_preview = CASE WHEN ultimo_mensaje_en IS NULL OR :last_at4 >= ultimo_mensaje_en THEN :preview ELSE ultimo_mensaje_preview END,
                ultimo_mensaje_origen = CASE WHEN ultimo_mensaje_en IS NULL OR :last_at5 >= ultimo_mensaje_en THEN :origen ELSE ultimo_mensaje_origen END
                WHERE id = :id");
            $update->execute([
                ':nombre'=>$name, ':bsuid'=>$bsuid, ':username'=>$username,
                ':origen_fuente'=>$originSource, ':origen_id'=>$originId, ':origen_url'=>$originUrl,
                ':origen_titulo'=>$originTitle, ':origen_media_url'=>$originMediaUrl, ':origen_ctwa'=>$originCtwa,
                ':history_estado'=>$history ? 1 : 0,
                ':history_resuelto'=>$history ? 1 : 0,
                ':unread'=>$history ? 0 : 1,
                ':first_at'=>$sentAt,
                ':last_at_cmp'=>$sentAt,
                ':last_at_set'=>$sentAt,
                ':last_at2'=>$sentAt,
                ':last_at3'=>$sentAt,
                ':last_at4'=>$sentAt,
                ':last_at5'=>$sentAt,
                ':tipo'=>$type,
                ':preview'=>$preview,
                ':origen'=>$history ? 'historial' : 'cliente',
                ':id'=>$conversationId,
            ]);
        }

        $insertMessage = $pdo->prepare("INSERT INTO whatsapp_mensajes
            (conversacion_id, wamid, ycloud_id, evento_id, direccion, origen, tipo, contenido,
             media_id, media_url, media_local_path, media_mime, media_filename, media_size,
             respuesta_automatica, fuera_horario, estado_envio, reply_to_wamid, creado_en)
            VALUES
            (:cid, :wamid, :ycloud_id, :event_id, 'entrante', :origen, :tipo, :contenido,
             :media_id, :media_url, :media_local, :mime, :filename, :size,
             0, :fuera, 'recibido', :reply_to, :created_at)");
        $insertMessage->execute([
            ':cid'=>$conversationId, ':wamid'=>$wamid, ':ycloud_id'=>$ycloudId !== '' ? $ycloudId : null,
            ':event_id'=>$eventId !== '' ? $eventId : null, ':origen'=>$history ? 'historial' : 'cliente',
            ':tipo'=>$type, ':contenido'=>$content !== '' ? $content : null, ':media_id'=>$media['id'] ?: null,
            ':media_url'=>$media['url'] ?: null, ':media_local'=>$media['local_path'] ?? null,
            ':mime'=>$media['mime'] ?: null, ':filename'=>$media['filename'] ?: null,
            ':size'=>$media['size'] ?: null, ':fuera'=>$outside,
            ':reply_to'=>trim((string)($message['context']['id'] ?? '')) ?: null, ':created_at'=>$sentAt,
        ]);
        $pdo->commit();

        return [
            'conversation_id'=>$conversationId, 'from'=>$from, 'to'=>$to, 'content'=>$content,
            'type'=>$type, 'outside_hours'=>$outside === 1,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function wa_webhook_store_app_outbound(PDO $pdo, array $message, string $eventId, bool $history = false, ?string $originOverride = null): ?array
{
    $from = wa_auto_digits((string)($message['from'] ?? ''));
    $to = wa_auto_digits((string)($message['to'] ?? ''));
    $expected = wa_auto_digits((string)WHATSAPP_YCLOUD_SENDER);
    if ($from !== $expected || $to === '') return null;

    $wamid = trim((string)($message['wamid'] ?? ''));
    $ycloudId = trim((string)($message['id'] ?? ''));
    if ($wamid === '') $wamid = $ycloudId !== '' ? 'ycloud:' . $ycloudId : ('ycloud-event:' . $eventId);

    $exists = $pdo->prepare("SELECT id FROM whatsapp_mensajes WHERE wamid = :wamid LIMIT 1");
    $exists->execute([':wamid' => $wamid]);
    if ($exists->fetchColumn()) return null;

    list($type, $content, $media) = wa_webhook_extract_content_media($message);
    $sentAt = wa_webhook_sql_datetime($message['sendTime'] ?? $message['createTime'] ?? null);
    $origin = $history ? 'historial' : ($originOverride ?: 'whatsapp_app');
    // Multimedia: guardar URL/metadata y descargar bajo demanda desde media.php.
    // Evita bloquear el webhook esperando archivos externos.

    $find = $pdo->prepare("SELECT id FROM whatsapp_conversaciones WHERE telefono = :telefono LIMIT 1");
    $find->execute([':telefono' => $to]);
    $conversationId = (int)($find->fetchColumn() ?: 0);
    if ($conversationId === 0) {
        $insert = $pdo->prepare("INSERT INTO whatsapp_conversaciones
            (telefono, estado, requiere_humano, primer_mensaje_en, primera_respuesta_humana_en,
             ultima_respuesta_humana_en, ultimo_mensaje_en, ultimo_mensaje_direccion, ultimo_mensaje_tipo,
             ultimo_mensaje_preview, ultimo_mensaje_origen)
            VALUES (:telefono, 'abierta', 0, NULL, :human_at, :human_at2, :last_at, 'saliente', :tipo, :preview, :origen)");
        $insert->execute([
            ':telefono'=>$to, ':human_at'=>$sentAt, ':human_at2'=>$sentAt, ':last_at'=>$sentAt,
            ':tipo'=>$type, ':preview'=>wa_webhook_preview($content, $type), ':origen'=>$origin,
        ]);
        $conversationId = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("INSERT INTO whatsapp_mensajes
        (conversacion_id, wamid, ycloud_id, evento_id, direccion, origen, tipo, contenido,
         media_id, media_url, media_local_path, media_mime, media_filename, media_size,
         respuesta_automatica, estado_envio, reply_to_wamid, creado_en, enviado_en)
        VALUES (:cid, :wamid, :ycloud_id, :event_id, 'saliente', :origen, :tipo, :contenido,
                :media_id, :media_url, :media_local, :mime, :filename, :size,
                0, :status, :reply_to, :created_at, :sent_at)");
    $stmt->execute([
        ':cid'=>$conversationId, ':wamid'=>$wamid, ':ycloud_id'=>$ycloudId !== '' ? $ycloudId : null,
        ':event_id'=>$eventId !== '' ? $eventId : null, ':origen'=>$origin, ':tipo'=>$type,
        ':contenido'=>$content !== '' ? $content : null, ':media_id'=>$media['id'] ?: null,
        ':media_url'=>$media['url'] ?: null, ':media_local'=>$media['local_path'] ?? null,
        ':mime'=>$media['mime'] ?: null, ':filename'=>$media['filename'] ?: null, ':size'=>$media['size'] ?: null,
        ':status'=>trim((string)($message['status'] ?? 'sent')) ?: 'sent',
        ':reply_to'=>trim((string)($message['context']['message_id'] ?? '')) ?: null,
        ':created_at'=>$sentAt, ':sent_at'=>$sentAt,
    ]);

    $update = $pdo->prepare("UPDATE whatsapp_conversaciones SET
        requiere_humano = CASE WHEN :history = 1 THEN requiere_humano ELSE 0 END,
        primera_respuesta_humana_en = COALESCE(primera_respuesta_humana_en, :human_at),
        ultima_respuesta_humana_en = :human_at2,
        ultimo_mensaje_en = CASE WHEN ultimo_mensaje_en IS NULL OR :last_at_cmp >= ultimo_mensaje_en THEN :last_at_set ELSE ultimo_mensaje_en END,
        ultimo_mensaje_direccion = CASE WHEN ultimo_mensaje_en IS NULL OR :last_at2 >= ultimo_mensaje_en THEN 'saliente' ELSE ultimo_mensaje_direccion END,
        ultimo_mensaje_tipo = CASE WHEN ultimo_mensaje_en IS NULL OR :last_at3 >= ultimo_mensaje_en THEN :tipo ELSE ultimo_mensaje_tipo END,
        ultimo_mensaje_preview = CASE WHEN ultimo_mensaje_en IS NULL OR :last_at4 >= ultimo_mensaje_en THEN :preview ELSE ultimo_mensaje_preview END,
        ultimo_mensaje_origen = CASE WHEN ultimo_mensaje_en IS NULL OR :last_at5 >= ultimo_mensaje_en THEN :origen ELSE ultimo_mensaje_origen END
        WHERE id = :id");
    $update->execute([
        ':history'=>$history ? 1 : 0, ':human_at'=>$sentAt, ':human_at2'=>$sentAt,
        ':last_at_cmp'=>$sentAt, ':last_at_set'=>$sentAt,
        ':last_at2'=>$sentAt, ':last_at3'=>$sentAt, ':last_at4'=>$sentAt, ':last_at5'=>$sentAt,
        ':tipo'=>$type, ':preview'=>wa_webhook_preview($content, $type), ':origen'=>$origin, ':id'=>$conversationId,
    ]);

    return ['conversation_id'=>$conversationId];
}

function wa_webhook_update_message_status(PDO $pdo, array $message): int
{
    $wamid = trim((string)($message['wamid'] ?? ''));
    $ycloudId = trim((string)($message['id'] ?? ''));
    $externalId = trim((string)($message['externalId'] ?? ''));
    $status = strtolower(trim((string)($message['status'] ?? '')));
    if ($status === '') return 0;

    $conditions = [];
    $params = [];
    if ($wamid !== '') { $conditions[] = 'wamid = :wamid'; $params[':wamid'] = $wamid; }
    if ($ycloudId !== '') { $conditions[] = 'ycloud_id = :ycloud_id'; $params[':ycloud_id'] = $ycloudId; }
    if ($externalId !== '') { $conditions[] = 'external_id = :external_id'; $params[':external_id'] = $externalId; }
    if (!$conditions) return 0;

    $sets = ['estado_envio = :status'];
    $params[':status'] = $status;

    $timeMap = [
        'sent' => ['enviado_en', $message['sendTime'] ?? $message['updateTime'] ?? null],
        'delivered' => ['entregado_en', $message['deliverTime'] ?? $message['updateTime'] ?? null],
        'read' => ['leido_en', $message['readTime'] ?? $message['updateTime'] ?? null],
        'failed' => ['fallido_en', $message['updateTime'] ?? null],
    ];
    if (isset($timeMap[$status])) {
        $sets[] = $timeMap[$status][0] . ' = :' . $timeMap[$status][0];
        $params[':' . $timeMap[$status][0]] = wa_webhook_sql_datetime($timeMap[$status][1]);
    }

    if ($status === 'failed') {
        $error = '';
        if (!empty($message['errors'][0]['message'])) $error = (string)$message['errors'][0]['message'];
        elseif (!empty($message['error']['message'])) $error = (string)$message['error']['message'];
        if ($error !== '') { $sets[] = 'error_envio = :error'; $params[':error'] = $error; }
    }
    if (isset($message['totalPrice'])) { $sets[] = 'precio = :precio'; $params[':precio'] = (float)$message['totalPrice']; }
    if (!empty($message['currency'])) { $sets[] = 'moneda = :moneda'; $params[':moneda'] = (string)$message['currency']; }
    if (!empty($message['pricingCategory'])) { $sets[] = 'pricing_category = :cat'; $params[':cat'] = (string)$message['pricingCategory']; }

    $stmt = $pdo->prepare("UPDATE whatsapp_mensajes SET " . implode(', ', $sets) . " WHERE (" . implode(' OR ', $conditions) . ")");
    $stmt->execute($params);
    return (int)$stmt->rowCount();
}

function wa_webhook_process_automation(PDO $pdo, array $stored): void
{
    $settings = wa_auto_settings($pdo);
    if ((int)$settings['automatizacion_activa'] !== 1) return;
    if (empty($stored['outside_hours'])) return;
    if (!wa_auto_ycloud_is_configured()) return;

    $response = wa_auto_build_response($pdo, (int)$stored['conversation_id'], (string)$stored['content'], $settings);
    $externalId = wa_auto_external_id('auto', (int)$stored['conversation_id']);
    $send = wa_auto_send_text((string)$stored['from'], (string)$response['body'], $externalId, 4);
    wa_auto_store_outgoing(
        $pdo,
        (int)$stored['conversation_id'],
        (string)$response['body'],
        $response['rule_id'],
        $send,
        'automatizacion',
        null,
        ['fuera_horario' => 1],
        $externalId
    );

    $update = $pdo->prepare("UPDATE whatsapp_conversaciones
        SET requiere_humano = :requiere WHERE id = :id");
    $update->execute([
        ':requiere' => ($response['requires_human'] || !($send['ok'] ?? false)) ? 1 : 0,
        ':id' => (int)$stored['conversation_id'],
    ]);
}



function wa_queue_root(): string
{
    return dirname(__DIR__, 2) . '/storage/whatsapp_queue';
}

function wa_queue_dir(string $name): string
{
    $dir = wa_queue_root() . '/' . $name;
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

function wa_queue_message_exists(PDO $pdo, array $message): bool
{
    $wamid = trim((string)($message['wamid'] ?? ''));
    $ycloudId = trim((string)($message['id'] ?? ''));
    $externalId = trim((string)($message['externalId'] ?? ''));

    $where = [];
    $params = [];
    if ($wamid !== '') {
        $where[] = 'wamid = :wamid';
        $params[':wamid'] = $wamid;
    }
    if ($ycloudId !== '') {
        $where[] = 'ycloud_id = :ycloud';
        $params[':ycloud'] = $ycloudId;
    }
    if ($externalId !== '') {
        $where[] = 'external_id = :external';
        $params[':external'] = $externalId;
    }

    if (!$where) return false;

    $stmt = $pdo->prepare("SELECT id FROM whatsapp_mensajes WHERE " . implode(' OR ', $where) . " LIMIT 1");
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}

/**
 * Corrige una clasificación provisional de YCloud cuando posteriormente llega
 * el evento autoritativo whatsapp.smb.message.echoes de Coexistence.
 *
 * Puede ocurrir que whatsapp.message.updated llegue antes que smb.message.echoes.
 * En ese caso el mensaje se guarda temporalmente como "ycloud" para no perderlo.
 * Cuando llega el echo de WhatsApp Business, este helper promueve únicamente
 * ese origen provisional a "whatsapp_app". Nunca pisa mensajes de S.I.G.O.I.,
 * automatizaciones u otros orígenes.
 */
function wa_queue_promote_ycloud_to_whatsapp_app(PDO $pdo, array $message): int
{
    $from = wa_auto_digits((string)($message['from'] ?? ''));
    $to = wa_auto_digits((string)($message['to'] ?? ''));
    $expected = wa_auto_digits((string)WHATSAPP_YCLOUD_SENDER);
    if ($from === '' || $from !== $expected || $to === '') return 0;

    $wamid = trim((string)($message['wamid'] ?? ''));
    $ycloudId = trim((string)($message['id'] ?? ''));
    $externalId = trim((string)($message['externalId'] ?? ''));

    $where = [];
    $params = [];
    if ($wamid !== '') {
        $where[] = 'wamid = :wamid';
        $params[':wamid'] = $wamid;
    }
    if ($ycloudId !== '') {
        $where[] = 'ycloud_id = :ycloud';
        $params[':ycloud'] = $ycloudId;
    }
    if ($externalId !== '') {
        $where[] = 'external_id = :external';
        $params[':external'] = $externalId;
    }
    if (!$where) return 0;

    $find = $pdo->prepare("SELECT id, conversacion_id, origen
        FROM whatsapp_mensajes
        WHERE (" . implode(' OR ', $where) . ")
        ORDER BY id DESC LIMIT 1");
    $find->execute($params);
    $row = $find->fetch(PDO::FETCH_ASSOC);
    if (!$row || (string)$row['origen'] !== 'ycloud') return 0;

    $messageId = (int)$row['id'];
    $conversationId = (int)$row['conversacion_id'];

    $update = $pdo->prepare("UPDATE whatsapp_mensajes
        SET origen = 'whatsapp_app'
        WHERE id = :id AND origen = 'ycloud'");
    $update->execute([':id' => $messageId]);
    if ($update->rowCount() < 1) return 0;

    // Si además es el último mensaje de la conversación, corrige el resumen
    // que alimenta la lista lateral de la bandeja.
    $latest = $pdo->prepare("SELECT id FROM whatsapp_mensajes
        WHERE conversacion_id = :cid
        ORDER BY creado_en DESC, id DESC LIMIT 1");
    $latest->execute([':cid' => $conversationId]);
    if ((int)$latest->fetchColumn() === $messageId) {
        $conv = $pdo->prepare("UPDATE whatsapp_conversaciones
            SET ultimo_mensaje_origen = 'whatsapp_app'
            WHERE id = :cid");
        $conv->execute([':cid' => $conversationId]);
    }

    return 1;
}

function wa_queue_process_payload(PDO $pdo, array $payload): array
{
    $eventType = trim((string)($payload['type'] ?? ''));
    $eventId = trim((string)($payload['id'] ?? ''));

    if (wa_webhook_event_seen($pdo, $eventId, $eventType)) {
        return ['ok' => true, 'duplicate' => true, 'type' => $eventType];
    }

    try {
        if ($eventType === 'whatsapp.inbound_message.received') {
            $message = is_array($payload['whatsappInboundMessage'] ?? null)
                ? $payload['whatsappInboundMessage']
                : [];

            $stored = $message
                ? wa_webhook_store_inbound($pdo, $message, $eventId, false)
                : null;

            if ($stored) {
                /*
                 * Prioridad: guardar SIEMPRE el mensaje del cliente.
                 * Una falla del bot/API no debe impedir que la conversación
                 * aparezca en la bandeja de S.I.G.O.I.
                 */
                try {
                    wa_webhook_process_automation($pdo, $stored);
                } catch (Throwable $automationError) {
                    error_log('WhatsApp automatización (mensaje ya guardado): ' . $automationError->getMessage());
                }
            }

        } elseif ($eventType === 'whatsapp.smb.message.echoes') {
            $message = is_array($payload['whatsappMessage'] ?? null)
                ? $payload['whatsappMessage']
                : [];

            if ($message) {
                $storedApp = wa_webhook_store_app_outbound($pdo, $message, $eventId, false, 'whatsapp_app');

                // Si message.updated llegó primero, el mismo mensaje pudo quedar
                // provisionalmente como YCloud. El echo SMB es la señal autoritativa
                // de que salió desde la app WhatsApp Business, así que corregimos
                // únicamente ese caso sin duplicar el mensaje.
                if (!$storedApp) {
                    wa_queue_promote_ycloud_to_whatsapp_app($pdo, $message);
                }
            }

        } elseif ($eventType === 'whatsapp.message.updated') {
            $message = is_array($payload['whatsappMessage'] ?? null)
                ? $payload['whatsappMessage']
                : [];

            if ($message) {
                $knownBefore = wa_queue_message_exists($pdo, $message);
                wa_webhook_update_message_status($pdo, $message);

                /*
                 * Si el mensaje salió desde la bandeja web de YCloud y S.I.G.O.I.
                 * todavía no lo conoce, lo incorporamos como respuesta humana externa.
                 * Los mensajes enviados desde S.I.G.O.I. ya existen y solo actualizan estado.
                 */
                if (!$knownBefore) {
                    $from = wa_auto_digits((string)($message['from'] ?? ''));
                    $expected = wa_auto_digits((string)WHATSAPP_YCLOUD_SENDER);
                    if ($from !== '' && $from === $expected) {
                        wa_webhook_store_app_outbound($pdo, $message, $eventId, false, 'ycloud');
                    }
                }
            }

        } elseif ($eventType === 'whatsapp.smb.history') {
            if (is_array($payload['whatsappInboundMessage'] ?? null)) {
                wa_webhook_store_inbound($pdo, $payload['whatsappInboundMessage'], $eventId, true);
            } elseif (is_array($payload['whatsappMessage'] ?? null)) {
                wa_webhook_store_app_outbound($pdo, $payload['whatsappMessage'], $eventId, true, 'historial');
            }
        }

        wa_webhook_event_done($pdo, $eventId, null);
        return ['ok' => true, 'duplicate' => false, 'type' => $eventType];
    } catch (Throwable $e) {
        wa_webhook_event_done($pdo, $eventId, $e->getMessage());
        throw $e;
    }
}

function wa_queue_recover_stale(): void
{
    $processing = wa_queue_dir('processing');
    $pending = wa_queue_dir('pending');

    foreach (glob($processing . '/*.json') ?: [] as $file) {
        if (@filemtime($file) !== false && filemtime($file) < time() - 120) {
            @rename($file, $pending . '/' . basename($file));
        }
    }
}

function wa_queue_requeue_failed_v51_once(): int
{
    $root = wa_queue_root();
    if (!is_dir($root)) @mkdir($root, 0750, true);

    $marker = $root . '/.requeue_v51_done';
    if (is_file($marker)) return 0;

    $failed = wa_queue_dir('failed');
    $pending = wa_queue_dir('pending');
    $moved = 0;

    foreach (glob($failed . '/*.json') ?: [] as $file) {
        $dest = $pending . '/' . basename($file);
        if (is_file($dest)) {
            @unlink($file);
            continue;
        }

        $raw = @file_get_contents($file);
        $wrapper = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($wrapper)) {
            $wrapper['attempts'] = 0;
            unset($wrapper['last_error'], $wrapper['last_attempt_at']);
            $encoded = json_encode($wrapper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) @file_put_contents($file, $encoded, LOCK_EX);
        }

        if (@rename($file, $dest)) $moved++;
    }

    @file_put_contents($marker, date('c') . " moved=" . $moved);
    return $moved;
}

function wa_queue_process_pending(PDO $pdo, int $limit = 20): array
{
    $requeued = wa_queue_requeue_failed_v51_once();
    wa_queue_recover_stale();

    $root = wa_queue_root();
    if (!is_dir($root)) @mkdir($root, 0750, true);

    $lockPath = $root . '/worker.lock';
    $lock = @fopen($lockPath, 'c+');
    if (!$lock) {
        return ['processed' => 0, 'failed' => 0, 'busy' => false, 'error' => 'lock_open'];
    }

    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return ['processed' => 0, 'failed' => 0, 'busy' => true];
    }

    $pending = wa_queue_dir('pending');
    $processing = wa_queue_dir('processing');
    $failedDir = wa_queue_dir('failed');

    $files = glob($pending . '/*.json') ?: [];
    usort($files, function ($a, $b) {
        return ((int)@filemtime($a)) <=> ((int)@filemtime($b));
    });

    $processed = 0;
    $failed = 0;

    foreach (array_slice($files, 0, max(1, $limit)) as $file) {
        $processingFile = $processing . '/' . basename($file);
        if (!@rename($file, $processingFile)) continue;

        $raw = @file_get_contents($processingFile);
        $wrapper = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($wrapper) || !is_array($wrapper['payload'] ?? null)) {
            @rename($processingFile, $failedDir . '/' . basename($processingFile));
            $failed++;
            continue;
        }

        try {
            wa_queue_process_payload($pdo, $wrapper['payload']);
            @unlink($processingFile);
            $processed++;
        } catch (Throwable $e) {
            $wrapper['attempts'] = (int)($wrapper['attempts'] ?? 0) + 1;
            $wrapper['last_error'] = $e->getMessage();
            $wrapper['last_attempt_at'] = date('c');

            $encoded = json_encode($wrapper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($wrapper['attempts'] < 5 && $encoded !== false) {
                @file_put_contents($processingFile, $encoded, LOCK_EX);
                @rename($processingFile, $pending . '/' . basename($processingFile));
            } else {
                if ($encoded !== false) @file_put_contents($processingFile, $encoded, LOCK_EX);
                @rename($processingFile, $failedDir . '/' . basename($processingFile));
            }

            error_log('WhatsApp queue worker: ' . $e->getMessage());
            $failed++;
        }
    }

    @flock($lock, LOCK_UN);
    fclose($lock);

    return [
        'worker_version' => '5.1-inbound',
        'processed' => $processed,
        'failed' => $failed,
        'requeued_failed' => $requeued,
        'busy' => false,
        'pending' => count(glob($pending . '/*.json') ?: []),
    ];
}
