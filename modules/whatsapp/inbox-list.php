<?php
require_once __DIR__ . '/_inbox_helpers.php';
auth_require_whatsapp_permission('bandeja_ver');
auth_require_whatsapp_channel('whatsapp');

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
        $where[] = '(c.nombre_personalizado LIKE :q OR c.nombre_contacto LIKE :q OR c.telefono LIKE :q OR c.username_whatsapp LIKE :q OR c.ultimo_mensaje_preview LIKE :q OR c.notas_contacto LIKE :q)';
        $params[':q'] = '%' . $q . '%';
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

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['requiere_humano'] = (int)$row['requiere_humano'];
        $row['no_leidos'] = (int)$row['no_leidos'];
        $row['asignado_a'] = $row['asignado_a'] !== null ? (int)$row['asignado_a'] : null;
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
