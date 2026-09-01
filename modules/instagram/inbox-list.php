<?php

declare(strict_types=1);

require_once __DIR__ . '/_inbox_helpers.php';
auth_require_whatsapp_permission('bandeja_ver');
auth_require_whatsapp_channel('instagram');

try {
    global $pdo;
    instagram_ensure_schema($pdo);

    $q = instagram_clean_text($_GET['q'] ?? '', 120);
    $filter = instagram_clean_text($_GET['filter'] ?? 'all', 30);
    $allowed = ['all', 'pending', 'open', 'resolved', 'unread'];
    if (!in_array($filter, $allowed, true)) $filter = 'all';

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = '(c.nombre_personalizado LIKE :q OR c.nombre_contacto LIKE :q OR c.username LIKE :q OR c.igsid LIKE :q OR c.ultimo_mensaje_preview LIKE :q OR c.notas_contacto LIKE :q)';
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

    $sql = "SELECT c.id, c.igsid, c.nombre_contacto, c.nombre_personalizado, c.notas_contacto,
        c.username AS username_whatsapp, c.foto_perfil_url, c.estado, c.requiere_humano,
        c.asignado_usuario_id AS asignado_a, c.no_leidos, c.ultimo_mensaje_en,
        c.ultimo_mensaje_direccion, c.ultimo_mensaje_tipo, c.ultimo_mensaje_preview,
        c.ultimo_mensaje_origen, c.primer_mensaje_en,
        u.nombre AS asignado_nombre
        FROM instagram_conversaciones c
        LEFT JOIN usuarios_sistema u ON u.id = c.asignado_usuario_id";

    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY COALESCE(c.ultimo_mensaje_en, c.creado_en) DESC LIMIT 120';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['canal'] = 'instagram';
        $row['telefono'] = null;
        $row['requiere_humano'] = (int)$row['requiere_humano'];
        $row['no_leidos'] = (int)$row['no_leidos'];
        $row['asignado_a'] = $row['asignado_a'] !== null ? (int)$row['asignado_a'] : null;
    }
    unset($row);

    instagram_json(true, '', [
        'conversaciones' => $rows,
        'total' => count($rows),
        'canal' => 'instagram',
        'send_enabled' => ig_inbox_send_enabled(),
        'can_modify' => auth_can_whatsapp_any(['bandeja_responder', 'bandeja_gestionar']),
        'can_reply' => auth_can_whatsapp('bandeja_responder'),
        'can_manage' => auth_can_whatsapp('bandeja_gestionar'),
    ]);
} catch (Throwable $e) {
    error_log('Instagram inbox-list: ' . $e->getMessage());
    instagram_json(false, 'No se pudo cargar la bandeja de Instagram.', [], 500);
}
