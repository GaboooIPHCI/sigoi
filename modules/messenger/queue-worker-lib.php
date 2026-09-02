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

    curl_setopt_array($ch, [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'SIGOI-Messenger/1.0',
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
        || $code < 200
        || $code >= 300
        || $downloadedSize <= 0
        || $downloadedSize > (25 * 1024 * 1024)
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

    $isEcho = !empty($message['is_echo']);

    $pageId = $isEcho ? $senderId : $recipientId;
    $psid = $isEcho ? $recipientId : $senderId;

    if ($pageId === '' || $psid === '') {
        return;
    }

    $configuredPageId = messenger_page_id();

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
    $origin = $isEcho ? 'messenger_app' : 'cliente';
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
