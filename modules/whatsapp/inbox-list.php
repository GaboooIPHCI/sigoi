<?php
require_once __DIR__ . '/_inbox_helpers.php';
auth_require_whatsapp_permission('bandeja_ver');
auth_require_whatsapp_channel('whatsapp');

function whatsapp_search_excerpt(string $content, string $query, int $maxLength = 180): string
{
    $content = trim($content);
    $query = trim($query);
    if ($content === '' || $query === '') return $content;

    if (function_exists('mb_stripos') && function_exists('mb_substr') && function_exists('mb_strlen')) {
        $position = mb_stripos($content, $query, 0, 'UTF-8');
        $length = mb_strlen($content, 'UTF-8');
        if ($position === false) $position = 0;
        $start = max(0, (int)$position - 55);
        $excerpt = mb_substr($content, $start, $maxLength, 'UTF-8');
        if ($start > 0) $excerpt = '…' . $excerpt;
        if (($start + $maxLength) < $length) $excerpt .= '…';
        return $excerpt;
    }

    $position = stripos($content, $query);
    $length = strlen($content);
    if ($position === false) $position = 0;
    $start = max(0, (int)$position - 55);
    $excerpt = substr($content, $start, $maxLength);
    if ($start > 0) $excerpt = '…' . $excerpt;
    if (($start + $maxLength) < $length) $excerpt .= '…';
    return $excerpt;
}

try {
    global $pdo;

    $q = whatsapp_clean_text($_GET['q'] ?? '', 120);
    $filter = whatsapp_clean_text($_GET['filter'] ?? 'all', 30);
    $allowed = ['all', 'pending', 'open', 'resolved', 'unread'];
    if (!in_array($filter, $allowed, true)) {
        $filter = 'all';
    }

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = '(
            c.nombre_personalizado LIKE :q_name
            OR c.nombre_contacto LIKE :q_contact
            OR c.telefono LIKE :q_phone
            OR c.username_whatsapp LIKE :q_username
            OR c.ultimo_mensaje_preview LIKE :q_preview
            OR c.notas_contacto LIKE :q_notes
            OR EXISTS (
                SELECT 1
                FROM whatsapp_mensajes sm
                WHERE sm.conversacion_id = c.id
                  AND sm.contenido LIKE :q_message
            )
        )';

        $like = '%' . $q . '%';
        $params[':q_name'] = $like;
        $params[':q_contact'] = $like;
        $params[':q_phone'] = $like;
        $params[':q_username'] = $like;
        $params[':q_preview'] = $like;
        $params[':q_notes'] = $like;
        $params[':q_message'] = $like;
    }

    if ($filter === 'pending') {
        $where[] = "c.requiere_humano = 1 AND c.estado <> 'cerrada'";
    } elseif ($filter === 'open') {
        $where[] = "c.estado <> 'cerrada'";
    } elseif ($filter === 'resolved') {
        $where[] = "c.estado = 'cerrada'";
    } elseif ($filter === 'unread') {
        $where[] = 'c.no_leidos > 0';
    }

    $sql = "SELECT c.id, c.telefono, c.nombre_contacto, c.nombre_personalizado, c.notas_contacto, c.username_whatsapp, c.estado,
        c.requiere_humano, c.asignado_a, c.no_leidos, c.ultimo_mensaje_en,
        c.ultimo_mensaje_direccion, c.ultimo_mensaje_tipo, c.ultimo_mensaje_preview,
        c.ultimo_mensaje_origen, c.primer_mensaje_en, c.primera_respuesta_humana_en,
        u.nombre AS asignado_nombre
        FROM whatsapp_conversaciones c
        LEFT JOIN usuarios_sistema u ON u.id = c.asignado_a";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY COALESCE(c.ultimo_mensaje_en, c.creado_en) DESC LIMIT 120';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $matchStmt = null;
    if ($q !== '') {
        $matchStmt = $pdo->prepare("
            SELECT contenido, tipo, creado_en
            FROM whatsapp_mensajes
            WHERE conversacion_id = :id
              AND contenido LIKE :q
            ORDER BY creado_en DESC, id DESC
            LIMIT 1
        ");
    }

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['requiere_humano'] = (int)$row['requiere_humano'];
        $row['no_leidos'] = (int)$row['no_leidos'];
        $row['asignado_a'] = $row['asignado_a'] !== null ? (int)$row['asignado_a'] : null;
        $row['busqueda_coincidencia'] = null;

        if ($matchStmt) {
            $matchStmt->execute([
                ':id' => $row['id'],
                ':q' => '%' . $q . '%',
            ]);

            $match = $matchStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($match) {
                $content = trim((string)($match['contenido'] ?? ''));
                if ($content === '') {
                    $content = '[' . ucfirst((string)($match['tipo'] ?? 'mensaje')) . ']';
                }

                $content = whatsapp_search_excerpt($content, $q, 180);

                $row['busqueda_coincidencia'] = $content;
                $row['ultimo_mensaje_preview'] = 'Coincidencia: ' . $content;
            } elseif (stripos((string)($row['notas_contacto'] ?? ''), $q) !== false) {
                $note = whatsapp_search_excerpt((string)$row['notas_contacto'], $q, 180);
                $row['busqueda_coincidencia'] = 'Nota: ' . $note;
                $row['ultimo_mensaje_preview'] = 'Coincidencia en nota: ' . $note;
            }
        }

        if (trim((string)$row['ultimo_mensaje_preview']) === '') {
            $previewStmt = $pdo->prepare("SELECT contenido, tipo FROM whatsapp_mensajes
                WHERE conversacion_id = :id ORDER BY creado_en DESC, id DESC LIMIT 1");
            $previewStmt->execute([':id' => $row['id']]);
            $last = $previewStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $preview = trim((string)($last['contenido'] ?? ''));
            if ($preview === '') {
                $preview = '[' . ucfirst((string)($last['tipo'] ?? 'mensaje')) . ']';
            }
            $row['ultimo_mensaje_preview'] = $preview;
        }
    }
    unset($row);

    whatsapp_json(true, '', [
        'conversaciones' => $rows,
        'total' => count($rows),
        'can_modify' => auth_can_whatsapp_any(['bandeja_responder', 'bandeja_gestionar']),
        'can_reply' => auth_can_whatsapp('bandeja_responder'),
        'can_manage' => auth_can_whatsapp('bandeja_gestionar'),
    ]);
} catch (Throwable $e) {
    error_log('WhatsApp inbox-list: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo cargar la bandeja de WhatsApp.', [], 500);
}
