<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

function messenger_event_timestamp(?int $milliseconds): string
{
    if (!$milliseconds) {
        return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    $seconds = (int)floor($milliseconds / 1000);

    return (new DateTimeImmutable('@' . $seconds))
        ->setTimezone(new DateTimeZone('America/Lima'))
        ->format('Y-m-d H:i:s');
}

function messenger_conversation_ensure(
    PDO $pdo,
    string $pageId,
    string $psid,
    string $createdAt
): array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM messenger_conversaciones
        WHERE page_id = :page_id
          AND psid = :psid
        LIMIT 1
    ");
    $stmt->execute([
        ':page_id' => $pageId,
        ':psid' => $psid,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return $row;
    }

    $insert = $pdo->prepare("
        INSERT INTO messenger_conversaciones (
            page_id,
            psid,
            primer_mensaje_en,
            ultimo_mensaje_en,
            creado_en
        )
        VALUES (
            :page_id,
            :psid,
            :primer_mensaje_en,
            :ultimo_mensaje_en,
            :creado_en
        )
    ");

    $insert->execute([
        ':page_id' => $pageId,
        ':psid' => $psid,
        ':primer_mensaje_en' => $createdAt,
        ':ultimo_mensaje_en' => $createdAt,
        ':creado_en' => $createdAt,
    ]);

    $id = (int)$pdo->lastInsertId();

    $profile = messenger_profile($pdo, $psid);

    if ($profile) {
        $pdo->prepare("
            UPDATE messenger_conversaciones
            SET nombre_contacto = :nombre,
                foto_perfil_url = :foto
            WHERE id = :id
        ")->execute([
            ':nombre' => $profile['name'],
            ':foto' => $profile['profile_pic'],
            ':id' => $id,
        ]);
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM messenger_conversaciones
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);

    return (array)$stmt->fetch(PDO::FETCH_ASSOC);
}

function messenger_media_download(
    string $url,
    string $type,
    string $fallbackName
): array {
    if (
        !preg_match('#^https://#i', $url)
        || !function_exists('curl_init')
    ) {
        return [
            'path' => null,
            'url' => $url,
            'mime' => null,
            'filename' => $fallbackName,
            'size' => null,
        ];
    }

    $dir = messenger_ensure_storage_dir('incoming');

    $tmp = tempnam($dir, 'msg_');

    if (!$tmp) {
        return [
            'path' => null,
            'url' => $url,
            'mime' => null,
            'filename' => $fallbackName,
            'size' => null,
        ];
    }

    $handle = fopen($tmp, 'wb');

    if (!$handle) {
        @unlink($tmp);

        return [
            'path' => null,
            'url' => $url,
            'mime' => null,
            'filename' => $fallbackName,
            'size' => null,
        ];
    }

    $ch = curl_init($url);
    $maxBytes = messenger_media_limit($type);
    if ($maxBytes <= 0) {
        $maxBytes = 25 * 1024 * 1024;
    }
    $tooLarge = false;

    curl_setopt_array($ch, [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'SIGOI-Messenger/1.0',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_XFERINFOFUNCTION => static function (
            $resource,
            float $downloadSize,
            float $downloaded,
            float $uploadSize,
            float $uploaded
        ) use ($maxBytes, &$tooLarge): int {
            if ($downloadSize > $maxBytes || $downloaded > $maxBytes) {
                $tooLarge = true;
                return 1;
            }
            return 0;
        },
    ]);

    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime = trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));

    curl_close($ch);
    fclose($handle);

    $downloadedSize = is_file($tmp)
        ? (int)filesize($tmp)
        : 0;

    if (
        !$ok
        || $tooLarge
        || $code < 200
        || $code >= 300
        || $downloadedSize <= 0
        || $downloadedSize > $maxBytes
    ) {
        @unlink($tmp);

        return [
            'path' => null,
            'url' => $url,
            'mime' => $mime !== '' ? $mime : null,
            'filename' => $fallbackName,
            'size' => null,
        ];
    }

    $filename = messenger_safe_filename($fallbackName, 'messenger');
    $ext = messenger_extension_from_mime($mime, $filename);

    try {
        $random = bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        $random = sha1(uniqid('', true));
    }

    $target = $dir . '/' . date('Ymd_His') . '_' . $random . '.' . $ext;

    if (!@rename($tmp, $target)) {
        if (!@copy($tmp, $target)) {
            @unlink($tmp);

            return [
                'path' => null,
                'url' => $url,
                'mime' => $mime !== '' ? $mime : null,
                'filename' => $filename,
                'size' => null,
            ];
        }

        @unlink($tmp);
    }

    @chmod($target, 0640);

    return [
        'path' => messenger_storage_relative($target),
        'url' => $url,
        'mime' => $mime !== '' ? $mime : null,
        'filename' => $filename,
        'size' => (int)filesize($target),
    ];
}

function messenger_store_message(
    PDO $pdo,
    int $conversationId,
    string $mid,
    string $direction,
    string $origin,
    string $type,
    string $body,
    ?array $media,
    string $createdAt,
    ?string $replyToMid = null,
    ?int $userId = null
): int {
    $existing = $pdo->prepare("
        SELECT id
        FROM messenger_mensajes
        WHERE mid = :mid
        LIMIT 1
    ");
    $existing->execute([':mid' => $mid]);

    $existingId = (int)($existing->fetchColumn() ?: 0);

    if ($existingId > 0) {
        return $existingId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO messenger_mensajes (
            conversacion_id,
            mid,
            direccion,
            origen,
            usuario_id,
            tipo,
            contenido,
            media_url,
            media_path,
            media_mime,
            media_filename,
            media_size,
            reply_to_mid,
            estado_envio,
            creado_en
        )
        VALUES (
            :conversation_id,
            :mid,
            :direction,
            :origin,
            :user_id,
            :type,
            :body,
            :media_url,
            :media_path,
            :media_mime,
            :media_filename,
            :media_size,
            :reply_to_mid,
            :estado_envio,
            :created_at
        )
    ");

    $stmt->execute([
        ':conversation_id' => $conversationId,
        ':mid' => $mid,
        ':direction' => $direction,
        ':origin' => $origin,
        ':user_id' => $userId,
        ':type' => $type,
        ':body' => $body !== '' ? $body : null,
        ':media_url' => $media['url'] ?? null,
        ':media_path' => $media['path'] ?? null,
        ':media_mime' => $media['mime'] ?? null,
        ':media_filename' => $media['filename'] ?? null,
        ':media_size' => $media['size'] ?? null,
        ':reply_to_mid' => $replyToMid,
        ':estado_envio' => $direction === 'saliente' ? 'sent' : 'recibido',
        ':created_at' => $createdAt,
    ]);

    return (int)$pdo->lastInsertId();
}

function messenger_update_conversation_after_message(
    PDO $pdo,
    int $conversationId,
    string $mid,
    string $direction,
    string $origin,
    string $type,
    string $preview,
    string $createdAt
): void {
    $incoming = $direction === 'entrante';

    $sql = "
        UPDATE messenger_conversaciones
        SET
            ultimo_mensaje_en = :created_at,
            ultimo_mensaje_direccion = :direction,
            ultimo_mensaje_tipo = :type,
            ultimo_mensaje_preview = :preview,
            ultimo_mensaje_origen = :origin,
            ultimo_mensaje_id = :mid,
            primer_mensaje_en = COALESCE(primer_mensaje_en, :created_at_first)
    ";

    if ($incoming) {
        $sql .= ",
            no_leidos = no_leidos + 1,
            requiere_humano = 1,
            estado = 'abierta',
            resuelto_en = NULL
        ";
    }

    $sql .= " WHERE id = :id";

    $pdo->prepare($sql)->execute([
        ':created_at' => $createdAt,
        ':direction' => $direction,
        ':type' => $type,
        ':preview' => messenger_clean_text($preview, 300),
        ':origin' => $origin,
        ':mid' => $mid,
        ':created_at_first' => $createdAt,
        ':id' => $conversationId,
    ]);
}

function messenger_referral_from_event(array $event): array
{
    $candidates = [];

    if (is_array($event['referral'] ?? null)) {
        $candidates[] = $event['referral'];
    }

    if (is_array($event['message']['referral'] ?? null)) {
        $candidates[] = $event['message']['referral'];
    }

    if (is_array($event['postback']['referral'] ?? null)) {
        $candidates[] = $event['postback']['referral'];
    }

    foreach ($candidates as $referral) {
        $source = strtoupper(trim((string)($referral['source'] ?? '')));
        $type = trim((string)($referral['type'] ?? ''));
        $adId = trim((string)($referral['ad_id'] ?? ''));
        $ref = trim((string)($referral['ref'] ?? ''));
        $uri = trim((string)(
            $referral['referer_uri']
            ?? $referral['referrer_uri']
            ?? ''
        ));

        if ($source !== '' || $type !== '' || $adId !== '' || $ref !== '' || $uri !== '') {
            return [
                'source' => messenger_clean_text($source, 40),
                'type' => messenger_clean_text($type, 80),
                'ad_id' => messenger_clean_text($adId, 120),
                'ref' => messenger_clean_text($ref, 255),
                'referer_uri' => preg_match('#^https?://#i', $uri) ? $uri : '',
            ];
        }
    }

    return [
        'source' => '',
        'type' => '',
        'ad_id' => '',
        'ref' => '',
        'referer_uri' => '',
    ];
}

function messenger_store_referral(PDO $pdo, int $conversationId, array $event): void
{
    if ($conversationId <= 0) return;

    $referral = messenger_referral_from_event($event);

    if (
        $referral['source'] === ''
        && $referral['type'] === ''
        && $referral['ad_id'] === ''
        && $referral['ref'] === ''
        && $referral['referer_uri'] === ''
    ) {
        return;
    }

    $pdo->prepare("\n        UPDATE messenger_conversaciones\n        SET\n            origen_fuente = CASE WHEN :source <> '' THEN :source_value ELSE origen_fuente END,\n            origen_tipo = CASE WHEN :type <> '' THEN :type_value ELSE origen_tipo END,\n            origen_ad_id = CASE WHEN :ad_id <> '' THEN :ad_id_value ELSE origen_ad_id END,\n            origen_ref = CASE WHEN :ref <> '' THEN :ref_value ELSE origen_ref END,\n            origen_referer_uri = CASE WHEN :uri <> '' THEN :uri_value ELSE origen_referer_uri END\n        WHERE id = :id\n    ")->execute([
        ':source' => $referral['source'],
        ':source_value' => $referral['source'],
        ':type' => $referral['type'],
        ':type_value' => $referral['type'],
        ':ad_id' => $referral['ad_id'],
        ':ad_id_value' => $referral['ad_id'],
        ':ref' => $referral['ref'],
        ':ref_value' => $referral['ref'],
        ':uri' => $referral['referer_uri'],
        ':uri_value' => $referral['referer_uri'],
        ':id' => $conversationId,
    ]);
}

function messenger_process_message_event(PDO $pdo, array $event): void
{
    $senderId = trim((string)($event['sender']['id'] ?? ''));
    $recipientId = trim((string)($event['recipient']['id'] ?? ''));
    $timestamp = (int)($event['timestamp'] ?? 0);
    $message = is_array($event['message'] ?? null)
        ? $event['message']
        : null;

    if (!$message) {
        return;
    }

    $configuredPageId = messenger_page_id();

    $isEcho = !empty($message['is_echo']);

    /*
     * Algunos eventos originados por la propia Página/Business Suite
     * pueden llegar sin is_echo explícito dependiendo del flujo de
     * control. Si el remitente es nuestra Page ID, lo tratamos como
     * saliente igualmente.
     */
    if (
        !$isEcho
        && $configuredPageId !== ''
        && hash_equals($configuredPageId, $senderId)
        && $recipientId !== ''
    ) {
        $isEcho = true;
    }

    $pageId = $isEcho ? $senderId : $recipientId;
    $psid = $isEcho ? $recipientId : $senderId;

    if ($pageId === '' || $psid === '') {
        return;
    }

    if (
        $configuredPageId !== ''
        && !hash_equals($configuredPageId, $pageId)
    ) {
        return;
    }

    $createdAt = messenger_event_timestamp($timestamp);

    $conversation = messenger_conversation_ensure(
        $pdo,
        $pageId,
        $psid,
        $createdAt
    );

    $conversationId = (int)$conversation['id'];
    messenger_store_referral($pdo, $conversationId, $event);

    $baseMid = trim((string)($message['mid'] ?? ''));

    if ($baseMid === '') {
        $baseMid = 'msg_' . hash(
            'sha256',
            json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /*
     * Si el eco corresponde a un mensaje que S.I.G.O.I. ya almacenó
     * al enviarlo, solo confirmamos el estado y no duplicamos.
     */
    if ($isEcho) {
        $existing = $pdo->prepare("
            SELECT id
            FROM messenger_mensajes
            WHERE mid = :mid
            LIMIT 1
        ");
        $existing->execute([':mid' => $baseMid]);

        if ($existing->fetchColumn()) {
            $pdo->prepare("
                UPDATE messenger_mensajes
                SET estado_envio = CASE
                        WHEN estado_envio IN ('failed','error') THEN estado_envio
                        ELSE 'sent'
                    END
                WHERE mid = :mid
            ")->execute([':mid' => $baseMid]);

            return;
        }
    }

    $direction = $isEcho ? 'saliente' : 'entrante';

    /*
     * Los MID enviados por S.I.G.O.I. ya se resolvieron arriba.
     * Para ecos externos:
     * - app_id presente => automatización/app externa (Meta Business Suite)
     * - sin app_id       => respuesta humana desde Messenger/Page Inbox
     */
    $echoAppId = trim((string)($message['app_id'] ?? ''));
    $origin = $isEcho
        ? ($echoAppId !== '' ? 'automatizacion' : 'messenger_app')
        : 'cliente';
    $body = messenger_clean_text($message['text'] ?? '', 10000);
    $replyToMid = messenger_clean_text(
        $message['reply_to']['mid'] ?? '',
        255
    );

    $attachments = is_array($message['attachments'] ?? null)
        ? $message['attachments']
        : [];

    $storedAny = false;

    if ($body !== '') {
        messenger_store_message(
            $pdo,
            $conversationId,
            $baseMid,
            $direction,
            $origin,
            'text',
            $body,
            null,
            $createdAt,
            $replyToMid !== '' ? $replyToMid : null
        );

        messenger_update_conversation_after_message(
            $pdo,
            $conversationId,
            $baseMid,
            $direction,
            $origin,
            'text',
            $body,
            $createdAt
        );

        $storedAny = true;
    }

    foreach ($attachments as $index => $attachment) {
        if (!is_array($attachment)) {
            continue;
        }

        $type = strtolower(trim((string)($attachment['type'] ?? 'file')));

        if (!in_array($type, ['image', 'video', 'audio', 'file'], true)) {
            $type = 'file';
        }

        $payload = is_array($attachment['payload'] ?? null)
            ? $attachment['payload']
            : [];

        $url = trim((string)($payload['url'] ?? ''));

        $filename = 'Messenger ' . ucfirst($type);

        $media = $url !== ''
            ? messenger_media_download($url, $type, $filename)
            : [
                'path' => null,
                'url' => null,
                'mime' => null,
                'filename' => $filename,
                'size' => null,
            ];

        $attachmentMid = $storedAny
            ? $baseMid . '#' . ($index + 1)
            : ($index === 0 ? $baseMid : $baseMid . '#' . ($index + 1));

        $preview = '[' . ucfirst($type) . ']';

        messenger_store_message(
            $pdo,
            $conversationId,
            $attachmentMid,
            $direction,
            $origin,
            $type,
            '',
            $media,
            $createdAt,
            $replyToMid !== '' ? $replyToMid : null
        );

        messenger_update_conversation_after_message(
            $pdo,
            $conversationId,
            $attachmentMid,
            $direction,
            $origin,
            $type,
            $preview,
            $createdAt
        );

        $storedAny = true;
    }

    if (!$storedAny) {
        $preview = '[Mensaje de Messenger]';

        messenger_store_message(
            $pdo,
            $conversationId,
            $baseMid,
            $direction,
            $origin,
            'text',
            $preview,
            null,
            $createdAt,
            $replyToMid !== '' ? $replyToMid : null
        );

        messenger_update_conversation_after_message(
            $pdo,
            $conversationId,
            $baseMid,
            $direction,
            $origin,
            'text',
            $preview,
            $createdAt
        );
    }
}


function messenger_graph_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('America/Lima'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function messenger_message_near_duplicate(
    PDO $pdo,
    int $conversationId,
    string $direction,
    string $body,
    string $createdAt
): bool {
    if ($body === '') return false;

    $stmt = $pdo->prepare("
        SELECT id
        FROM messenger_mensajes
        WHERE conversacion_id = :conversation_id
          AND direccion = :direction
          AND contenido = :body
          AND creado_en BETWEEN
              DATE_SUB(:created_at_a, INTERVAL 4 SECOND)
              AND DATE_ADD(:created_at_b, INTERVAL 4 SECOND)
        LIMIT 1
    ");

    $stmt->execute([
        ':conversation_id' => $conversationId,
        ':direction' => $direction,
        ':body' => $body,
        ':created_at_a' => $createdAt,
        ':created_at_b' => $createdAt,
    ]);

    return (bool)$stmt->fetchColumn();
}

function messenger_sync_conversation_history(
    PDO $pdo,
    array $conversation,
    int $limit = 20
): array {
    $pageId = trim((string)($conversation['page_id'] ?? messenger_page_id()));
    $psid = trim((string)($conversation['psid'] ?? ''));
    $conversationId = (int)($conversation['id'] ?? 0);

    if (
        $conversationId <= 0
        || $pageId === ''
        || $psid === ''
        || messenger_page_token() === ''
        || messenger_api_version() === ''
    ) {
        return ['ok' => false, 'imported' => 0, 'error' => 'Sin datos suficientes para sincronizar el historial.'];
    }

    $limit = max(5, min(30, $limit));

    $findQuery = http_build_query([
        'platform' => 'messenger',
        'user_id' => $psid,
        'fields' => 'id',
        'limit' => 1,
    ]);

    $find = messenger_graph_request(
        $pageId . '/conversations?' . $findQuery,
        'GET',
        null,
        12
    );

    if (!($find['ok'] ?? false)) {
        return ['ok' => false, 'imported' => 0, 'error' => (string)($find['error'] ?? 'No se pudo localizar la conversación en Meta.')];
    }

    $remoteRows = (array)($find['data']['data'] ?? []);
    $remoteConversationId = trim((string)($remoteRows[0]['id'] ?? ''));

    if ($remoteConversationId === '') {
        return ['ok' => true, 'imported' => 0, 'error' => ''];
    }

    $fields = 'messages.limit(' . $limit . '){id,created_time,from,to,message}';
    $historyQuery = http_build_query(['fields' => $fields]);

    $history = messenger_graph_request(
        $remoteConversationId . '?' . $historyQuery,
        'GET',
        null,
        15
    );

    if (!($history['ok'] ?? false)) {
        return ['ok' => false, 'imported' => 0, 'error' => (string)($history['error'] ?? 'No se pudo leer el historial de Meta.')];
    }

    $messages = (array)($history['data']['messages']['data'] ?? []);
    $messages = array_reverse($messages);
    $imported = 0;

    foreach ($messages as $remote) {
        if (!is_array($remote)) continue;

        $remoteId = trim((string)($remote['id'] ?? ''));
        $createdAt = messenger_graph_datetime((string)($remote['created_time'] ?? ''));
        $body = messenger_clean_text($remote['message'] ?? '', 10000);

        if ($remoteId === '' || !$createdAt || $body === '') continue;

        $exists = $pdo->prepare("SELECT id FROM messenger_mensajes WHERE mid = :mid LIMIT 1");
        $exists->execute([':mid' => $remoteId]);
        if ($exists->fetchColumn()) continue;

        $from = is_array($remote['from'] ?? null) ? $remote['from'] : [];
        $fromId = trim((string)($from['id'] ?? ''));
        $direction = ($fromId !== '' && hash_equals($pageId, $fromId)) ? 'saliente' : 'entrante';

        if (messenger_message_near_duplicate($pdo, $conversationId, $direction, $body, $createdAt)) {
            continue;
        }

        /*
         * Si el mensaje está en Meta pero nunca llegó como message_echo,
         * Conversations API no expone de forma fiable si fue regla o humano.
         * Lo conservamos como salida externa de Meta para no perder historial.
         */
        $origin = $direction === 'saliente' ? 'meta_sync' : 'cliente';

        messenger_store_message(
            $pdo,
            $conversationId,
            $remoteId,
            $direction,
            $origin,
            'text',
            $body,
            null,
            $createdAt
        );

        messenger_update_conversation_after_message(
            $pdo,
            $conversationId,
            $remoteId,
            $direction,
            $origin,
            'text',
            $body,
            $createdAt
        );

        $imported++;
    }

    return ['ok' => true, 'imported' => $imported, 'error' => ''];
}

function messenger_sync_recent_histories(PDO $pdo, int $conversationLimit = 5): array
{
    messenger_ensure_schema($pdo);
    $conversationLimit = max(1, min(10, $conversationLimit));

    $rows = $pdo->query("
        SELECT *
        FROM messenger_conversaciones
        WHERE ultimo_mensaje_en >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ORDER BY ultimo_mensaje_en DESC
        LIMIT " . (int)$conversationLimit
    )->fetchAll(PDO::FETCH_ASSOC);

    $synced = 0;
    $imported = 0;
    $errors = 0;

    foreach ($rows as $row) {
        $result = messenger_sync_conversation_history($pdo, $row, 20);

        if ($result['ok'] ?? false) {
            $synced++;
            $imported += (int)($result['imported'] ?? 0);
        } else {
            $errors++;
            error_log('Messenger history sync: ' . (string)($result['error'] ?? 'Error desconocido'));
        }
    }

    return ['synced' => $synced, 'imported' => $imported, 'errors' => $errors];
}

function messenger_process_postback_event(PDO $pdo, array $event): void
{
    $senderId = trim((string)($event['sender']['id'] ?? ''));
    $pageId = trim((string)($event['recipient']['id'] ?? ''));

    if ($senderId === '' || $pageId === '') {
        return;
    }

    $configuredPageId = messenger_page_id();

    if (
        $configuredPageId !== ''
        && !hash_equals($configuredPageId, $pageId)
    ) {
        return;
    }

    $createdAt = messenger_event_timestamp(
        (int)($event['timestamp'] ?? 0)
    );

    $conversation = messenger_conversation_ensure(
        $pdo,
        $pageId,
        $senderId,
        $createdAt
    );

    messenger_store_referral($pdo, (int)$conversation['id'], $event);

    $postback = (array)($event['postback'] ?? []);

    $title = messenger_clean_text($postback['title'] ?? '', 1000);
    $payload = messenger_clean_text($postback['payload'] ?? '', 2000);

    $body = $title !== ''
        ? $title
        : ($payload !== '' ? $payload : '[Postback]');

    $mid = 'postback_' . hash(
        'sha256',
        json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    messenger_store_message(
        $pdo,
        (int)$conversation['id'],
        $mid,
        'entrante',
        'cliente',
        'text',
        $body,
        null,
        $createdAt
    );

    messenger_update_conversation_after_message(
        $pdo,
        (int)$conversation['id'],
        $mid,
        'entrante',
        'cliente',
        'text',
        $body,
        $createdAt
    );
}

function messenger_process_read_event(PDO $pdo, array $event): void
{
    $senderId = trim((string)($event['sender']['id'] ?? ''));
    $pageId = trim((string)($event['recipient']['id'] ?? ''));
    $watermark = (int)($event['read']['watermark'] ?? 0);

    if (
        $senderId === ''
        || $pageId === ''
        || $watermark <= 0
    ) {
        return;
    }

    $configuredPageId = messenger_page_id();

    if (
        $configuredPageId !== ''
        && !hash_equals($configuredPageId, $pageId)
    ) {
        return;
    }

    $conversation = messenger_conversation_ensure(
        $pdo,
        $pageId,
        $senderId,
        messenger_event_timestamp($watermark)
    );

    $readAt = messenger_event_timestamp($watermark);

    $pdo->prepare("
        UPDATE messenger_mensajes
        SET
            estado_envio = 'read',
            leido_en = COALESCE(leido_en, :read_at)
        WHERE conversacion_id = :conversation_id
          AND direccion = 'saliente'
          AND creado_en <= :watermark_at
    ")->execute([
        ':read_at' => $readAt,
        ':conversation_id' => (int)$conversation['id'],
        ':watermark_at' => $readAt,
    ]);
}

function messenger_process_delivery_event(PDO $pdo, array $event): void
{
    $senderId = trim((string)($event['sender']['id'] ?? ''));
    $pageId = trim((string)($event['recipient']['id'] ?? ''));

    if ($senderId === '' || $pageId === '') {
        return;
    }

    $configuredPageId = messenger_page_id();

    if (
        $configuredPageId !== ''
        && !hash_equals($configuredPageId, $pageId)
    ) {
        return;
    }

    $delivery = (array)($event['delivery'] ?? []);
    $watermark = (int)($delivery['watermark'] ?? 0);
    $mids = is_array($delivery['mids'] ?? null)
        ? $delivery['mids']
        : [];

    $conversation = messenger_conversation_ensure(
        $pdo,
        $pageId,
        $senderId,
        messenger_event_timestamp($watermark ?: null)
    );

    $deliveredAt = messenger_event_timestamp($watermark ?: null);

    if ($mids) {
        $stmt = $pdo->prepare("
            UPDATE messenger_mensajes
            SET
                estado_envio = CASE
                    WHEN estado_envio = 'read' THEN estado_envio
                    ELSE 'delivered'
                END,
                entregado_en = COALESCE(entregado_en, :delivered_at)
            WHERE mid = :mid
              AND conversacion_id = :conversation_id
        ");

        foreach ($mids as $mid) {
            $mid = trim((string)$mid);

            if ($mid === '') {
                continue;
            }

            $stmt->execute([
                ':delivered_at' => $deliveredAt,
                ':mid' => $mid,
                ':conversation_id' => (int)$conversation['id'],
            ]);
        }

        return;
    }

    if ($watermark > 0) {
        $pdo->prepare("
            UPDATE messenger_mensajes
            SET
                estado_envio = CASE
                    WHEN estado_envio = 'read' THEN estado_envio
                    ELSE 'delivered'
                END,
                entregado_en = COALESCE(entregado_en, :delivered_at)
            WHERE conversacion_id = :conversation_id
              AND direccion = 'saliente'
              AND creado_en <= :watermark_at
        ")->execute([
            ':delivered_at' => $deliveredAt,
            ':conversation_id' => (int)$conversation['id'],
            ':watermark_at' => $deliveredAt,
        ]);
    }
}

function messenger_process_event(PDO $pdo, array $event): void
{
    if (is_array($event['message'] ?? null)) {
        messenger_process_message_event($pdo, $event);
        return;
    }

    if (is_array($event['postback'] ?? null)) {
        messenger_process_postback_event($pdo, $event);
        return;
    }

    if (is_array($event['read'] ?? null)) {
        messenger_process_read_event($pdo, $event);
        return;
    }

    if (is_array($event['delivery'] ?? null)) {
        messenger_process_delivery_event($pdo, $event);
        return;
    }
}

function messenger_process_queue(PDO $pdo, int $limit = 25): array
{
    messenger_ensure_schema($pdo);

    $limit = max(1, min(100, $limit));

    /*
     * Recupera eventos que quedaron "procesando" por un worker interrumpido.
     */
    $pdo->exec("
        UPDATE messenger_eventos
        SET estado = 'pendiente'
        WHERE estado = 'procesando'
          AND actualizado_en < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");

    $stmt = $pdo->query("
        SELECT id, payload, intentos
        FROM messenger_eventos
        WHERE estado IN ('pendiente','error')
          AND intentos < 5
        ORDER BY id ASC
        LIMIT {$limit}
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $processed = 0;
    $failed = 0;

    foreach ($rows as $row) {
        $id = (int)$row['id'];

        $claim = $pdo->prepare("
            UPDATE messenger_eventos
            SET
                estado = 'procesando',
                intentos = intentos + 1,
                error = NULL
            WHERE id = :id
              AND estado IN ('pendiente','error')
        ");
        $claim->execute([':id' => $id]);

        if ($claim->rowCount() <= 0) {
            continue;
        }

        try {
            $event = json_decode(
                (string)$row['payload'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($event)) {
                throw new RuntimeException('Evento Messenger inválido.');
            }

            messenger_process_event($pdo, $event);

            $pdo->prepare("
                UPDATE messenger_eventos
                SET
                    estado = 'procesado',
                    procesado_en = NOW(),
                    error = NULL
                WHERE id = :id
            ")->execute([':id' => $id]);

            $processed++;
        } catch (Throwable $e) {
            $pdo->prepare("
                UPDATE messenger_eventos
                SET
                    estado = 'error',
                    error = :error
                WHERE id = :id
            ")->execute([
                ':error' => messenger_clean_text($e->getMessage(), 4000),
                ':id' => $id,
            ]);

            error_log(
                'Messenger queue #' . $id . ': ' . $e->getMessage()
            );

            $failed++;
        }
    }

    /*
     * Los enlaces temporales para archivos salientes dejan de ser públicos
     * cuando expiran, pero el archivo privado se conserva para el historial.
     */
    $pdo->exec("
        DELETE FROM messenger_outbox_media
        WHERE expira_en < NOW()
    ");

    return [
        'processed' => $processed,
        'failed' => $failed,
        'pending' => (int)$pdo->query("
            SELECT COUNT(*)
            FROM messenger_eventos
            WHERE estado IN ('pendiente','error')
              AND intentos < 5
        ")->fetchColumn(),
    ];
}
