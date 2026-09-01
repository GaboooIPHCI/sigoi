<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/instagram_meta.php';
require_once __DIR__ . '/../../config/instagram_schema.php';

function ig_queue_root(): string
{
    return dirname(__DIR__, 2) . '/storage/instagram_queue';
}

function ig_queue_dir(string $name): string
{
    $root = ig_queue_root();
    $dir = $root . '/' . trim($name, '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function ig_queue_ensure_storage(): void
{
    $root = ig_queue_root();
    foreach ([$root, ig_queue_dir('pending'), ig_queue_dir('processing'), ig_queue_dir('failed')] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
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
}

function ig_meta_token_is_configured(): bool
{
    return defined('INSTAGRAM_META_ACCESS_TOKEN')
        && trim((string)INSTAGRAM_META_ACCESS_TOKEN) !== ''
        && strpos((string)INSTAGRAM_META_ACCESS_TOKEN, 'PEGA_AQUI_') !== 0;
}

function ig_meta_app_secret_is_configured(): bool
{
    return defined('INSTAGRAM_META_APP_SECRET')
        && trim((string)INSTAGRAM_META_APP_SECRET) !== ''
        && strpos((string)INSTAGRAM_META_APP_SECRET, 'PEGA_AQUI_') !== 0;
}

function ig_meta_api_version(): string
{
    if (defined('INSTAGRAM_META_API_VERSION') && trim((string)INSTAGRAM_META_API_VERSION) !== '') {
        return trim((string)INSTAGRAM_META_API_VERSION);
    }

    // Coincide con la versión que actualmente muestra el panel de Meta del proyecto.
    return 'v26.0';
}

function ig_sql_datetime_from_millis($timestamp): string
{
    if (is_numeric($timestamp)) {
        $numeric = (float)$timestamp;
        if ($numeric > 100000000000.0) {
            $numeric /= 1000.0;
        }
        $seconds = (int)floor($numeric);
        try {
            $dt = (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('America/Lima'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            // Continúa con fallback.
        }
    }

    return date('Y-m-d H:i:s');
}

function ig_preview(string $content, string $type): string
{
    $content = trim($content);
    if ($content === '') {
        $content = '[' . ucfirst($type !== '' ? $type : 'mensaje') . ']';
    }

    return function_exists('mb_substr')
        ? mb_substr($content, 0, 280, 'UTF-8')
        : substr($content, 0, 280);
}

/**
 * Guarda una copia local de multimedia entrante. Las URLs que entrega Meta
 * pueden ser temporales; conservar el archivo permite que el historial de
 * S.I.G.O.I. siga siendo útil después.
 */
function ig_cache_remote_media(string $url, string $mid, string $type): array
{
    $url = trim($url);
    $type = strtolower(trim($type));
    if ($url === '' || !in_array($type, ['imagen', 'video', 'audio', 'archivo'], true) || !function_exists('curl_init')) {
        return [];
    }

    $projectRoot = dirname(__DIR__, 2);
    $relativeDir = 'storage/instagram_media/' . date('Y/m');
    $absoluteDir = $projectRoot . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        return [];
    }

    $tmp = @tempnam($absoluteDir, 'ig_');
    if (!is_string($tmp) || $tmp === '') return [];

    $fh = @fopen($tmp, 'wb');
    if (!$fh) {
        @unlink($tmp);
        return [];
    }

    $mime = '';
    $filename = '';
    $maxBytes = 25 * 1024 * 1024;
    $tooLarge = false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FAILONERROR => false,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_USERAGENT => 'SIGOI-Instagram-Media/1.0',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$mime, &$filename): int {
            $len = strlen($header);
            if (stripos($header, 'Content-Type:') === 0) {
                $mime = trim(explode(';', trim(substr($header, 13)), 2)[0]);
            } elseif (stripos($header, 'Content-Disposition:') === 0 && preg_match('/filename\*?=(?:UTF-8\'\')?["\']?([^"\';\r\n]+)/i', $header, $m)) {
                $filename = rawurldecode(trim($m[1]));
            }
            return $len;
        },
        CURLOPT_XFERINFOFUNCTION => static function ($resource, float $downloadSize, float $downloaded) use ($maxBytes, &$tooLarge): int {
            if ($downloadSize > $maxBytes || $downloaded > $maxBytes) {
                $tooLarge = true;
                return 1;
            }
            return 0;
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($mime === '') $mime = trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    curl_close($ch);
    fclose($fh);

    $size = is_file($tmp) ? (int)@filesize($tmp) : 0;
    if ($ok === false || $tooLarge || $code < 200 || $code >= 400 || $size <= 0 || $size > $maxBytes) {
        @unlink($tmp);
        return [];
    }

    $extMap = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav',
        'application/pdf' => 'pdf',
    ];
    $ext = $extMap[strtolower($mime)] ?? '';
    if ($ext === '') {
        $urlPath = (string)parse_url($url, PHP_URL_PATH);
        $candidate = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        if (preg_match('/^[a-z0-9]{1,8}$/', $candidate)) $ext = $candidate;
    }
    if ($ext === '') $ext = 'bin';

    $base = substr(hash('sha256', $mid !== '' ? $mid : ($url . microtime(true))), 0, 32);
    $finalRelative = $relativeDir . '/' . $base . '.' . $ext;
    $finalAbsolute = $projectRoot . '/' . $finalRelative;
    if (is_file($finalAbsolute)) {
        @unlink($tmp);
    } elseif (!@rename($tmp, $finalAbsolute)) {
        @unlink($tmp);
        return [];
    }

    if ($filename === '') {
        $filename = 'instagram_' . substr($base, 0, 10) . '.' . $ext;
    }
    $filename = preg_replace('/[^\pL\pN._ -]+/u', '_', basename($filename)) ?: ('archivo.' . $ext);

    return [
        'path' => $finalRelative,
        'mime' => $mime,
        'filename' => $filename,
        'size' => (int)@filesize($finalAbsolute),
    ];
}

/**
 * Obtiene los datos públicos permitidos del usuario que inició la conversación.
 * Si todavía no se configuró el ACCESS_TOKEN, simplemente devuelve un arreglo vacío.
 */
function ig_meta_fetch_profile(string $igsid): array
{
    $igsid = trim($igsid);
    if ($igsid === '' || !ig_meta_token_is_configured() || !function_exists('curl_init')) {
        return [];
    }

    $url = 'https://graph.instagram.com/' . rawurlencode(ig_meta_api_version())
        . '/' . rawurlencode($igsid)
        . '?fields=' . rawurlencode('id,name,username,profile_pic')
        . '&access_token=' . rawurlencode((string)INSTAGRAM_META_ACCESS_TOKEN);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $code < 200 || $code >= 300) {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    return [
        'name' => trim((string)($data['name'] ?? '')),
        'username' => trim((string)($data['username'] ?? '')),
        'profile_pic' => trim((string)($data['profile_pic'] ?? '')),
    ];
}

/**
 * Recupera el texto de un mensaje directamente desde la API de Instagram.
 * Se usa como respaldo cuando el webhook entrega el MID pero no incluye el
 * cuerpo del mensaje (puede ocurrir en algunos eventos/ediciones).
 */
function ig_meta_fetch_message_text(string $mid): string
{
    $mid = trim($mid);
    if ($mid === '' || !ig_meta_token_is_configured() || !function_exists('curl_init')) {
        return '';
    }

    $url = 'https://graph.instagram.com/' . rawurlencode(ig_meta_api_version())
        . '/' . rawurlencode($mid)
        . '?fields=' . rawurlencode('id,created_time,from,to,message');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . (string)INSTAGRAM_META_ACCESS_TOKEN,
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $code < 200 || $code >= 300) {
        return '';
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return '';
    }

    return trim((string)($data['message'] ?? ''));
}

function ig_extract_message(array $message): array
{
    $type = 'texto';
    $content = trim((string)($message['text'] ?? ''));
    $mediaUrl = null;

    if ($content !== '') {
        return [$type, $content, $mediaUrl];
    }

    $attachments = $message['attachments'] ?? null;
    if (is_array($attachments) && isset($attachments[0]) && is_array($attachments[0])) {
        $attachment = $attachments[0];
        $rawType = strtolower(trim((string)($attachment['type'] ?? 'archivo')));
        $payload = is_array($attachment['payload'] ?? null) ? $attachment['payload'] : [];
        $mediaUrl = trim((string)($payload['url'] ?? '')) ?: null;

        $labels = [
            'image' => ['imagen', '[Imagen]'],
            'video' => ['video', '[Video]'],
            'audio' => ['audio', '[Audio]'],
            'file' => ['archivo', '[Archivo]'],
            'share' => ['compartido', '[Contenido compartido]'],
            'reel' => ['reel', '[Reel compartido]'],
            'story' => ['historia', '[Historia]'],
        ];

        if (isset($labels[$rawType])) {
            [$type, $content] = $labels[$rawType];
        } else {
            $type = $rawType !== '' ? $rawType : 'archivo';
            $content = '[Contenido de Instagram]';
        }

        return [$type, $content, $mediaUrl];
    }

    $shares = $message['shares'] ?? null;
    if (is_array($shares) && isset($shares[0]) && is_array($shares[0])) {
        $share = $shares[0];
        $mediaUrl = trim((string)($share['link'] ?? $share['url'] ?? '')) ?: null;
        return ['compartido', '[Contenido compartido]', $mediaUrl];
    }

    if (!empty($message['is_deleted'])) {
        return ['eliminado', '[Mensaje eliminado]', null];
    }

    if (!empty($message['is_unsupported'])) {
        return ['unsupported', '[Mensaje no compatible]', null];
    }

    return ['mensaje', '[Mensaje de Instagram]', null];
}

function ig_find_conversation(PDO $pdo, string $accountId, string $igsid): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM instagram_conversaciones
        WHERE cuenta_ig_id = :account_id AND igsid = :igsid LIMIT 1");
    $stmt->execute([':account_id' => $accountId, ':igsid' => $igsid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ig_upsert_conversation(
    PDO $pdo,
    string $accountId,
    string $igsid,
    string $direction,
    string $origin,
    string $type,
    string $content,
    string $mid,
    string $createdAt,
    bool $incrementUnread
): int {
    $existing = ig_find_conversation($pdo, $accountId, $igsid);

    $profile = [];
    if (!$existing || trim((string)($existing['username'] ?? '')) === '') {
        $profile = ig_meta_fetch_profile($igsid);
    }

    $name = trim((string)($profile['name'] ?? ''));
    $username = trim((string)($profile['username'] ?? ''));
    $profilePic = trim((string)($profile['profile_pic'] ?? ''));
    $preview = ig_preview($content, $type);

    if (!$existing) {
        $stmt = $pdo->prepare("INSERT INTO instagram_conversaciones
            (cuenta_ig_id, igsid, nombre_contacto, username, foto_perfil_url, estado, requiere_humano, no_leidos,
             primer_mensaje_en, ultimo_mensaje_en, ultimo_mensaje_direccion, ultimo_mensaje_tipo,
             ultimo_mensaje_preview, ultimo_mensaje_origen, ultimo_mensaje_id)
            VALUES
            (:account_id, :igsid, :name, :username, :profile_pic, 'abierta', :requires_human, :unread,
             :first_at, :last_at, :direction, :type, :preview, :origin, :mid)");
        $stmt->execute([
            ':account_id' => $accountId,
            ':igsid' => $igsid,
            ':name' => $name !== '' ? $name : null,
            ':username' => $username !== '' ? $username : null,
            ':profile_pic' => $profilePic !== '' ? $profilePic : null,
            ':requires_human' => $direction === 'entrante' ? 1 : 0,
            ':unread' => $incrementUnread ? 1 : 0,
            ':first_at' => $createdAt,
            ':last_at' => $createdAt,
            ':direction' => $direction,
            ':type' => $type,
            ':preview' => $preview,
            ':origin' => $origin,
            ':mid' => $mid,
        ]);

        return (int)$pdo->lastInsertId();
    }

    $conversationId = (int)$existing['id'];
    $stmt = $pdo->prepare("UPDATE instagram_conversaciones SET
        nombre_contacto = COALESCE(NULLIF(:name, ''), nombre_contacto),
        username = COALESCE(NULLIF(:username, ''), username),
        foto_perfil_url = COALESCE(NULLIF(:profile_pic, ''), foto_perfil_url),
        estado = 'abierta',
        resuelto_en = NULL,
        requiere_humano = CASE
            WHEN :incoming_pending = 1 THEN 1
            WHEN :outgoing_clear = 1 THEN 0
            ELSE requiere_humano
        END,
        no_leidos = no_leidos + :unread,
        primer_mensaje_en = COALESCE(primer_mensaje_en, :first_at),
        ultimo_mensaje_en = CASE WHEN ultimo_mensaje_en IS NULL OR :cmp_at >= ultimo_mensaje_en THEN :last_at ELSE ultimo_mensaje_en END,
        ultimo_mensaje_direccion = CASE WHEN ultimo_mensaje_en IS NULL OR :cmp_at2 >= ultimo_mensaje_en THEN :direction ELSE ultimo_mensaje_direccion END,
        ultimo_mensaje_tipo = CASE WHEN ultimo_mensaje_en IS NULL OR :cmp_at3 >= ultimo_mensaje_en THEN :type ELSE ultimo_mensaje_tipo END,
        ultimo_mensaje_preview = CASE WHEN ultimo_mensaje_en IS NULL OR :cmp_at4 >= ultimo_mensaje_en THEN :preview ELSE ultimo_mensaje_preview END,
        ultimo_mensaje_origen = CASE WHEN ultimo_mensaje_en IS NULL OR :cmp_at5 >= ultimo_mensaje_en THEN :origin ELSE ultimo_mensaje_origen END,
        ultimo_mensaje_id = CASE WHEN ultimo_mensaje_en IS NULL OR :cmp_at6 >= ultimo_mensaje_en THEN :mid ELSE ultimo_mensaje_id END
        WHERE id = :id");
    $stmt->execute([
        ':name' => $name,
        ':username' => $username,
        ':profile_pic' => $profilePic,
        ':incoming_pending' => $direction === 'entrante' ? 1 : 0,
        ':outgoing_clear' => $direction === 'saliente' ? 1 : 0,
        ':unread' => $incrementUnread ? 1 : 0,
        ':first_at' => $createdAt,
        ':cmp_at' => $createdAt,
        ':last_at' => $createdAt,
        ':cmp_at2' => $createdAt,
        ':direction' => $direction,
        ':cmp_at3' => $createdAt,
        ':type' => $type,
        ':cmp_at4' => $createdAt,
        ':preview' => $preview,
        ':cmp_at5' => $createdAt,
        ':origin' => $origin,
        ':cmp_at6' => $createdAt,
        ':mid' => $mid,
        ':id' => $conversationId,
    ]);

    return $conversationId;
}

function ig_store_message_event(PDO $pdo, string $accountId, array $event): bool
{
    $message = is_array($event['message'] ?? null) ? $event['message'] : [];
    if (!$message) {
        return false;
    }

    $mid = trim((string)($message['mid'] ?? ''));
    if ($mid === '') {
        return false;
    }

    $existing = $pdo->prepare("SELECT id, origen FROM instagram_mensajes WHERE mid = :mid LIMIT 1");
    $existing->execute([':mid' => $mid]);
    $existingRow = $existing->fetch(PDO::FETCH_ASSOC);

    $isEcho = !empty($message['is_echo']);
    $senderId = trim((string)($event['sender']['id'] ?? ''));
    $recipientId = trim((string)($event['recipient']['id'] ?? ''));

    $direction = $isEcho ? 'saliente' : 'entrante';
    $igsid = $isEcho ? $recipientId : $senderId;
    if ($igsid === '' || $igsid === $accountId) {
        return false;
    }

    [$type, $content, $mediaUrl] = ig_extract_message($message);

    // En ciertos webhooks reales Meta puede entregar el MID sin el texto.
    // Si tenemos token, recuperamos el cuerpo usando el propio MID antes de guardarlo.
    if ($mediaUrl === null && ($content === '' || $content === '[Mensaje de Instagram]' || $content === '[Texto]')) {
        $apiText = ig_meta_fetch_message_text($mid);
        if ($apiText !== '') {
            $type = 'texto';
            $content = $apiText;
        }
    }

    $createdAt = ig_sql_datetime_from_millis($event['timestamp'] ?? null);
    $replyTo = trim((string)($message['reply_to']['mid'] ?? '')) ?: null;

    if ($existingRow) {
        // Si en el futuro S.I.G.O.I. inserta primero el mensaje saliente, el eco de
        // Instagram solo confirma el envío y conserva su origen original.
        if ($isEcho) {
            $stmt = $pdo->prepare("UPDATE instagram_mensajes SET
                estado_envio = 'enviado',
                actualizado_en = CURRENT_TIMESTAMP
                WHERE mid = :mid");
            $stmt->execute([':mid' => $mid]);
        }
        return false;
    }

    $origin = $isEcho ? 'instagram_app' : 'cliente';
    $cachedMedia = $mediaUrl !== null ? ig_cache_remote_media($mediaUrl, $mid, $type) : [];

    $pdo->beginTransaction();
    try {
        $conversationId = ig_upsert_conversation(
            $pdo,
            $accountId,
            $igsid,
            $direction,
            $origin,
            $type,
            $content,
            $mid,
            $createdAt,
            !$isEcho
        );

        $stmt = $pdo->prepare("INSERT INTO instagram_mensajes
            (conversacion_id, mid, direccion, origen, tipo, contenido, media_url,
             media_path, media_mime, media_filename, media_size, reply_to_mid, estado_envio, creado_en)
            VALUES
            (:cid, :mid, :direction, :origin, :type, :content, :media_url,
             :media_path, :media_mime, :media_filename, :media_size, :reply_to, :status, :created_at)");
        $stmt->execute([
            ':cid' => $conversationId,
            ':mid' => $mid,
            ':direction' => $direction,
            ':origin' => $origin,
            ':type' => $type,
            ':content' => $content !== '' ? $content : null,
            ':media_url' => $mediaUrl,
            ':media_path' => $cachedMedia['path'] ?? null,
            ':media_mime' => $cachedMedia['mime'] ?? null,
            ':media_filename' => $cachedMedia['filename'] ?? null,
            ':media_size' => $cachedMedia['size'] ?? null,
            ':reply_to' => $replyTo,
            ':status' => $isEcho ? 'enviado' : 'recibido',
            ':created_at' => $createdAt,
        ]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function ig_store_postback_event(PDO $pdo, string $accountId, array $event): bool
{
    $postback = is_array($event['postback'] ?? null) ? $event['postback'] : [];
    if (!$postback) {
        return false;
    }

    $igsid = trim((string)($event['sender']['id'] ?? ''));
    if ($igsid === '' || $igsid === $accountId) {
        return false;
    }

    $mid = trim((string)($postback['mid'] ?? ''));
    if ($mid === '') {
        $mid = 'ig-postback:' . hash('sha256', json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($event));
    }

    $exists = $pdo->prepare("SELECT id FROM instagram_mensajes WHERE mid = :mid LIMIT 1");
    $exists->execute([':mid' => $mid]);
    if ($exists->fetchColumn()) {
        return false;
    }

    $title = trim((string)($postback['title'] ?? ''));
    $payload = trim((string)($postback['payload'] ?? ''));
    $content = $title !== '' ? $title : ($payload !== '' ? $payload : '[Acción de Instagram]');
    $createdAt = ig_sql_datetime_from_millis($event['timestamp'] ?? null);

    $pdo->beginTransaction();
    try {
        $conversationId = ig_upsert_conversation(
            $pdo, $accountId, $igsid, 'entrante', 'cliente', 'postback', $content, $mid, $createdAt, true
        );

        $stmt = $pdo->prepare("INSERT INTO instagram_mensajes
            (conversacion_id, mid, direccion, origen, tipo, contenido, estado_envio, creado_en)
            VALUES (:cid, :mid, 'entrante', 'cliente', 'postback', :content, 'recibido', :created_at)");
        $stmt->execute([
            ':cid' => $conversationId,
            ':mid' => $mid,
            ':content' => $content,
            ':created_at' => $createdAt,
        ]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function ig_apply_seen_event(PDO $pdo, array $event): bool
{
    $read = is_array($event['read'] ?? null) ? $event['read'] : [];
    $mid = trim((string)($read['mid'] ?? ''));
    if ($mid === '') {
        return false;
    }

    $target = $pdo->prepare("SELECT conversacion_id, creado_en FROM instagram_mensajes WHERE mid = :mid LIMIT 1");
    $target->execute([':mid' => $mid]);
    $row = $target->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;

    $readAt = ig_sql_datetime_from_millis($event['timestamp'] ?? null);
    // Si Instagram informa que el usuario vio un mensaje, los salientes
    // anteriores de la misma conversación también se consideran vistos.
    $stmt = $pdo->prepare("UPDATE instagram_mensajes SET
        estado_envio = 'leido', leido_en = COALESCE(leido_en, :read_at)
        WHERE conversacion_id = :cid
          AND direccion = 'saliente'
          AND creado_en <= :created_at");
    $stmt->execute([
        ':read_at' => $readAt,
        ':cid' => (int)$row['conversacion_id'],
        ':created_at' => (string)$row['creado_en'],
    ]);
    return true;
}

function ig_apply_reaction_event(PDO $pdo, array $event): bool
{
    $reaction = is_array($event['reaction'] ?? null) ? $event['reaction'] : [];
    $mid = trim((string)($reaction['mid'] ?? ''));
    if ($mid === '') {
        return false;
    }

    $action = strtolower(trim((string)($reaction['action'] ?? 'react')));
    $name = trim((string)($reaction['reaction'] ?? ''));
    $emoji = trim((string)($reaction['emoji'] ?? ''));

    if ($action === 'unreact' || $action === 'remove') {
        $name = '';
        $emoji = '';
    }

    $stmt = $pdo->prepare("UPDATE instagram_mensajes SET
        reaccion = :reaction, reaccion_emoji = :emoji
        WHERE mid = :mid");
    $stmt->execute([
        ':reaction' => $name !== '' ? $name : null,
        ':emoji' => $emoji !== '' ? $emoji : null,
        ':mid' => $mid,
    ]);

    return $stmt->rowCount() > 0;
}

function ig_apply_edit_event(PDO $pdo, array $event): bool
{
    $edit = is_array($event['message_edit'] ?? null) ? $event['message_edit'] : [];
    $mid = trim((string)($edit['mid'] ?? ''));
    if ($mid === '') {
        return false;
    }

    $text = trim((string)($edit['text'] ?? ''));
    if ($text === '') {
        $text = ig_meta_fetch_message_text($mid);
    }

    // Meta documenta num_edit para las ediciones reales. No asumimos 1 cuando
    // el campo no viene: algunos eventos pueden traer message_edit como una
    // actualización de contenido y eso no debe marcar visualmente el mensaje
    // como "Editado" si Meta no confirmó una edición.
    $numEdit = (int)($edit['num_edit'] ?? 0);
    $isConfirmedEdit = $numEdit > 0;
    $editedAt = ig_sql_datetime_from_millis($event['timestamp'] ?? null);

    // Nunca borrar un contenido que ya teníamos solo porque Meta envió una
    // notificación sin text. Si num_edit no está presente, actualizamos el
    // contenido (si existe) pero conservamos intactos los metadatos de edición.
    if ($text !== '' && $isConfirmedEdit) {
        $stmt = $pdo->prepare("UPDATE instagram_mensajes SET
            contenido = :content,
            tipo = 'texto',
            editado_veces = GREATEST(editado_veces, :num_edit),
            editado_en = :edited_at
            WHERE mid = :mid");
        $stmt->execute([
            ':content' => $text,
            ':num_edit' => $numEdit,
            ':edited_at' => $editedAt,
            ':mid' => $mid,
        ]);
    } elseif ($text !== '') {
        $stmt = $pdo->prepare("UPDATE instagram_mensajes SET
            contenido = :content,
            tipo = 'texto'
            WHERE mid = :mid");
        $stmt->execute([
            ':content' => $text,
            ':mid' => $mid,
        ]);
    } elseif ($isConfirmedEdit) {
        $stmt = $pdo->prepare("UPDATE instagram_mensajes SET
            editado_veces = GREATEST(editado_veces, :num_edit),
            editado_en = :edited_at
            WHERE mid = :mid");
        $stmt->execute([
            ':num_edit' => $numEdit,
            ':edited_at' => $editedAt,
            ':mid' => $mid,
        ]);
    } else {
        // Evento sin texto y sin num_edit confirmado: no hay nada seguro que
        // modificar. Se considera atendido para evitar falsos "Editado".
        return true;
    }

    if ($stmt->rowCount() <= 0) {
        return false;
    }

    if ($text !== '') {
        // Mantener actualizado el preview si este era el último mensaje de su conversación.
        $preview = ig_preview($text, 'texto');
        $pdo->prepare("UPDATE instagram_conversaciones c
            INNER JOIN instagram_mensajes m ON m.conversacion_id = c.id
            SET c.ultimo_mensaje_preview = :preview, c.ultimo_mensaje_tipo = 'texto'
            WHERE m.mid = :mid AND c.ultimo_mensaje_id = :mid2")
            ->execute([':preview' => $preview, ':mid' => $mid, ':mid2' => $mid]);
    }

    return true;
}

/**
 * Repara mensajes ya guardados cuyo webhook llegó sin texto.
 * Se ejecuta solo al abrir una conversación y únicamente para filas vacías
 * o placeholders, por lo que no altera mensajes que ya tienen contenido.
 */
function ig_repair_conversation_texts(PDO $pdo, int $conversationId, int $limit = 12): int
{
    if ($conversationId <= 0 || !ig_meta_token_is_configured()) {
        return 0;
    }

    $limit = max(1, min(30, $limit));
    $stmt = $pdo->prepare("SELECT id, mid, contenido, tipo FROM instagram_mensajes
        WHERE conversacion_id = :cid
          AND direccion = 'entrante'
          AND (contenido IS NULL OR TRIM(contenido) = '' OR contenido IN ('[Mensaje de Instagram]', '[Texto]'))
        ORDER BY id DESC
        LIMIT {$limit}");
    $stmt->execute([':cid' => $conversationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $repaired = 0;
    foreach ($rows as $row) {
        $mid = trim((string)($row['mid'] ?? ''));
        if ($mid === '') {
            continue;
        }

        $text = ig_meta_fetch_message_text($mid);
        if ($text === '') {
            continue;
        }

        $pdo->prepare("UPDATE instagram_mensajes
            SET contenido = :content, tipo = 'texto', actualizado_en = CURRENT_TIMESTAMP
            WHERE id = :id")
            ->execute([':content' => $text, ':id' => (int)$row['id']]);

        $pdo->prepare("UPDATE instagram_conversaciones
            SET ultimo_mensaje_preview = CASE WHEN ultimo_mensaje_id = :mid THEN :preview ELSE ultimo_mensaje_preview END,
                ultimo_mensaje_tipo = CASE WHEN ultimo_mensaje_id = :mid2 THEN 'texto' ELSE ultimo_mensaje_tipo END
            WHERE id = :cid")
            ->execute([
                ':mid' => $mid,
                ':preview' => ig_preview($text, 'texto'),
                ':mid2' => $mid,
                ':cid' => $conversationId,
            ]);
        $repaired++;
    }

    return $repaired;
}

function ig_queue_process_payload(PDO $pdo, array $payload): array
{
    instagram_ensure_schema($pdo);

    if (trim((string)($payload['object'] ?? '')) !== 'instagram') {
        return ['stored' => 0, 'updated' => 0, 'ignored' => 1];
    }

    $stored = 0;
    $updated = 0;
    $ignored = 0;

    $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            $ignored++;
            continue;
        }

        $accountId = trim((string)($entry['id'] ?? ''));
        if ($accountId === '') {
            $accountId = defined('INSTAGRAM_META_ACCOUNT_ID') ? trim((string)INSTAGRAM_META_ACCOUNT_ID) : '';
        }

        $messaging = is_array($entry['messaging'] ?? null) ? $entry['messaging'] : [];
        foreach ($messaging as $event) {
            if (!is_array($event)) {
                $ignored++;
                continue;
            }

            if (is_array($event['message'] ?? null)) {
                if (ig_store_message_event($pdo, $accountId, $event)) {
                    $stored++;
                } else {
                    $ignored++;
                }
                continue;
            }

            if (is_array($event['postback'] ?? null)) {
                if (ig_store_postback_event($pdo, $accountId, $event)) {
                    $stored++;
                } else {
                    $ignored++;
                }
                continue;
            }

            if (is_array($event['read'] ?? null)) {
                if (ig_apply_seen_event($pdo, $event)) {
                    $updated++;
                } else {
                    $ignored++;
                }
                continue;
            }

            if (is_array($event['reaction'] ?? null)) {
                if (ig_apply_reaction_event($pdo, $event)) {
                    $updated++;
                } else {
                    $ignored++;
                }
                continue;
            }

            if (is_array($event['message_edit'] ?? null)) {
                if (ig_apply_edit_event($pdo, $event)) {
                    $updated++;
                } else {
                    $ignored++;
                }
                continue;
            }

            // referral/optin/handover/standby quedan preparados para una fase posterior.
            $ignored++;
        }

        // Los webhooks de comments/live_comments llegan con field/value, no como DMs.
        // En esta fase se confirman sin convertirlos en conversaciones.
        if (isset($entry['field']) || isset($entry['changes'])) {
            $ignored++;
        }
    }

    return ['stored' => $stored, 'updated' => $updated, 'ignored' => $ignored];
}

function ig_queue_recover_stale(): void
{
    ig_queue_ensure_storage();
    $processing = ig_queue_dir('processing');
    $pending = ig_queue_dir('pending');

    $files = glob($processing . '/*.json') ?: [];
    $threshold = time() - 300;
    foreach ($files as $file) {
        $mtime = (int)@filemtime($file);
        if ($mtime > 0 && $mtime < $threshold) {
            @rename($file, $pending . '/' . basename($file));
        }
    }
}

function ig_queue_process_pending(PDO $pdo, int $limit = 50): array
{
    instagram_ensure_schema($pdo);
    ig_queue_recover_stale();

    $root = ig_queue_root();
    $lockPath = $root . '/worker.lock';
    $lock = @fopen($lockPath, 'c+');
    if (!$lock) {
        return ['processed' => 0, 'failed' => 0, 'busy' => false, 'error' => 'lock_open'];
    }

    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return ['processed' => 0, 'failed' => 0, 'busy' => true];
    }

    $pending = ig_queue_dir('pending');
    $processing = ig_queue_dir('processing');
    $failedDir = ig_queue_dir('failed');

    $files = glob($pending . '/*.json') ?: [];
    usort($files, static function (string $a, string $b): int {
        return ((int)@filemtime($a)) <=> ((int)@filemtime($b));
    });

    $processed = 0;
    $failed = 0;
    $stored = 0;
    $updated = 0;
    $ignored = 0;

    foreach (array_slice($files, 0, max(1, $limit)) as $file) {
        $processingFile = $processing . '/' . basename($file);
        if (!@rename($file, $processingFile)) {
            continue;
        }

        $raw = @file_get_contents($processingFile);
        $wrapper = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($wrapper) || !is_array($wrapper['payload'] ?? null)) {
            @rename($processingFile, $failedDir . '/' . basename($processingFile));
            $failed++;
            continue;
        }

        try {
            $result = ig_queue_process_payload($pdo, $wrapper['payload']);
            $stored += (int)($result['stored'] ?? 0);
            $updated += (int)($result['updated'] ?? 0);
            $ignored += (int)($result['ignored'] ?? 0);
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
                if ($encoded !== false) {
                    @file_put_contents($processingFile, $encoded, LOCK_EX);
                }
                @rename($processingFile, $failedDir . '/' . basename($processingFile));
            }

            error_log('Instagram queue worker: ' . $e->getMessage());
            $failed++;
        }
    }

    @flock($lock, LOCK_UN);
    fclose($lock);

    return [
        'processed' => $processed,
        'stored' => $stored,
        'updated' => $updated,
        'ignored' => $ignored,
        'failed' => $failed,
        'busy' => false,
        'pending' => count(glob($pending . '/*.json') ?: []),
    ];
}
