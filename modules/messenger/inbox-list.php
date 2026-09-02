<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

auth_require_whatsapp_permission('bandeja_ver');

try {
    global $pdo;

    messenger_ensure_schema($pdo);
    messenger_require_channel_permission($pdo);

    $q = messenger_clean_text($_GET['q'] ?? '', 160);
    $filter = messenger_clean_text($_GET['filter'] ?? 'all', 30);

    if (!in_array($filter, ['all', 'pending', 'unread', 'resolved'], true)) {
        $filter = 'all';
    }

    $where = [];
    $params = [];

    if ($filter === 'pending') {
        $where[] = "c.requiere_humano = 1 AND c.estado <> 'cerrada'";
    } elseif ($filter === 'unread') {
        $where[] = 'c.no_leidos > 0';
    } elseif ($filter === 'resolved') {
        $where[] = "c.estado = 'cerrada'";
    }

    if ($q !== '') {
        $where[] = "(
            c.nombre_contacto LIKE :q
            OR c.nombre_personalizado LIKE :q
            OR c.psid LIKE :q
            OR c.notas_contacto LIKE :q
            OR c.ultimo_mensaje_preview LIKE :q
            OR EXISTS (
                SELECT 1
                FROM messenger_mensajes sm
                WHERE sm.conversacion_id = c.id
                  AND sm.contenido LIKE :q
            )
        )";
        $params[':q'] = '%' . $q . '%';
    }

    $sql = "
        SELECT
            c.id,
            c.page_id,
            c.psid,
            c.nombre_contacto,
            c.nombre_personalizado,
            c.foto_perfil_url,
            c.origen_fuente,
            c.origen_tipo,
            c.origen_ad_id,
            c.origen_ref,
            c.origen_referer_uri,
            c.notas_contacto,
            c.estado,
            c.requiere_humano,
            c.no_leidos,
            c.asignado_usuario_id AS asignado_a,
            c.primer_mensaje_en,
            c.ultimo_mensaje_en,
            c.ultimo_mensaje_direccion,
            c.ultimo_mensaje_tipo,
            c.ultimo_mensaje_preview,
            c.ultimo_mensaje_origen,
            c.resuelto_en,
            c.creado_en,
            u.nombre AS asignado_nombre
    ";

    if ($q !== '') {
        $sql .= ",
            (
                SELECT sm.contenido
                FROM messenger_mensajes sm
                WHERE sm.conversacion_id = c.id
                  AND sm.contenido LIKE :q_match
                ORDER BY sm.creado_en DESC, sm.id DESC
                LIMIT 1
            ) AS coincidencia_mensaje
        ";
        $params[':q_match'] = '%' . $q . '%';
    } else {
        $sql .= ", NULL AS coincidencia_mensaje";
    }

    $sql .= "
        FROM messenger_conversaciones c

        LEFT JOIN usuarios_sistema u
            ON u.id = c.asignado_usuario_id
    ";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= "
        ORDER BY
            COALESCE(c.ultimo_mensaje_en, c.creado_en) DESC,
            c.id DESC
        LIMIT 250
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row['id'] = (int)$row['id'];
        $row['requiere_humano'] = (int)$row['requiere_humano'];
        $row['no_leidos'] = (int)$row['no_leidos'];
        $row['asignado_a'] = $row['asignado_a'] !== null
            ? (int)$row['asignado_a']
            : null;

        $row['canal'] = 'messenger';
        $row['telefono'] = null;
        $row['username_whatsapp'] = null;

        if ($q !== '' && !empty($row['coincidencia_mensaje'])) {
            $text = trim((string)$row['coincidencia_mensaje']);

            if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > 220) {
                $text = mb_substr($text, 0, 220, 'UTF-8') . '…';
            } elseif (strlen($text) > 220) {
                $text = substr($text, 0, 220) . '…';
            }

            $row['match_preview'] = $text;
        } else {
            $row['match_preview'] = null;
        }

        unset($row['coincidencia_mensaje']);

        $rows[] = $row;
    }

    messenger_json(true, '', [
        'canal' => 'messenger',
        'conversaciones' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('Messenger inbox-list: ' . $e->getMessage());

    messenger_json(
        false,
        'No se pudo cargar la bandeja de Messenger.',
        [],
        500
    );
}
