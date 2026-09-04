<?php
require_once __DIR__ . '/_template_helpers.php';
auth_require_whatsapp_permission('bandeja_ver');
auth_require_whatsapp_channel('whatsapp');

try {
    global $pdo;
    $id = (int)($_GET['id'] ?? 0); if ($id <= 0) whatsapp_json(false, 'Conversación inválida.', [], 422);
    $conversation = wa_inbox_conversation_row($pdo, $id); if (!$conversation) whatsapp_json(false, 'La conversación ya no existe.', [], 404);

    $limit = max(20, min(100, (int)($_GET['limit'] ?? 60)));
    $beforeId = max(0, (int)($_GET['before_id'] ?? 0));
    $beforeTime = trim((string)($_GET['before_time'] ?? ''));
    $cursorSql = ''; $params = [':id' => $id];
    if ($beforeId > 0 && $beforeTime !== '') {
        $cursorSql = ' AND (creado_en < :before_time_a OR (creado_en = :before_time_b AND id < :before_id))';
        $params[':before_time_a'] = $beforeTime; $params[':before_time_b'] = $beforeTime; $params[':before_id'] = $beforeId;
    }
    $fetchLimit = $limit + 1;
    $stmt = $pdo->prepare("SELECT m.*, u.nombre AS usuario_nombre, r.nombre AS regla_nombre
        FROM (SELECT id FROM whatsapp_mensajes WHERE conversacion_id = :id {$cursorSql}
              ORDER BY creado_en DESC, id DESC LIMIT {$fetchLimit}) recent
        INNER JOIN whatsapp_mensajes m ON m.id = recent.id
        LEFT JOIN usuarios_sistema u ON u.id = m.usuario_id
        LEFT JOIN whatsapp_reglas r ON r.id = m.regla_id
        ORDER BY m.creado_en ASC, m.id ASC");
    $stmt->execute($params); $messageRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($messageRows) > $limit; if ($hasMore) array_shift($messageRows);

    $templateMap = [];
    if ($messageRows) {
        try {
            $ids = array_map(static function ($row) { return (int)$row['id']; }, $messageRows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $tpl = $pdo->prepare("SELECT mensaje_id, plantilla_nombre, plantilla_idioma, plantilla_categoria, variables_json
                FROM whatsapp_plantilla_envios WHERE mensaje_id IN ({$placeholders})");
            $tpl->execute($ids);
            foreach ($tpl->fetchAll(PDO::FETCH_ASSOC) as $row) $templateMap[(int)$row['mensaje_id']] = $row;
        } catch (Throwable $ignored) { $templateMap = []; }
    }

    $messages = [];
    foreach ($messageRows as $row) {
        $payload = wa_inbox_message_payload($row); $meta = $templateMap[(int)$row['id']] ?? null;
        if ($meta) {
            $variables = json_decode((string)($meta['variables_json'] ?? ''), true);
            $payload['plantilla'] = ['name'=>(string)$meta['plantilla_nombre'],'language'=>(string)($meta['plantilla_idioma'] ?? ''),'category'=>(string)($meta['plantilla_categoria'] ?? ''),'variables'=>is_array($variables)?array_values($variables):[]];
        } else $payload['plantilla'] = null;
        $messages[] = $payload;
    }

    $window = wa_inbox_send_window($pdo, $id);
    if ($beforeId <= 0 && (int)$conversation['no_leidos'] > 0) {
        $pdo->prepare("UPDATE whatsapp_conversaciones SET no_leidos = 0 WHERE id = :id")->execute([':id'=>$id]); $conversation['no_leidos'] = 0;
    }
    $firstHumanSeconds = null;
    if (!empty($conversation['primer_mensaje_en']) && !empty($conversation['primera_respuesta_humana_en'])) {
        try { $firstHumanSeconds = max(0, (new DateTimeImmutable((string)$conversation['primera_respuesta_humana_en']))->getTimestamp() - (new DateTimeImmutable((string)$conversation['primer_mensaje_en']))->getTimestamp()); } catch (Throwable $e) {}
    }
    $conversation['id']=(int)$conversation['id']; $conversation['requiere_humano']=(int)$conversation['requiere_humano'];
    $conversation['asignado_a']=$conversation['asignado_a']!==null?(int)$conversation['asignado_a']:null; $conversation['no_leidos']=(int)$conversation['no_leidos'];
    $conversation['total_mensajes']=(int)$conversation['total_mensajes']; $conversation['total_entrantes']=(int)$conversation['total_entrantes']; $conversation['total_salientes']=(int)$conversation['total_salientes'];
    $conversation['tiempo_primera_respuesta_humana_seg']=$firstHumanSeconds;
    $oldest = $messages[0] ?? null;
    whatsapp_json(true, '', ['conversacion'=>$conversation,'mensajes'=>$messages,'historial'=>[
        'has_more'=>$hasMore,'limit'=>$limit,'cursor'=>$oldest?['id'=>(int)$oldest['id'],'creado_en'=>(string)$oldest['creado_en']]:null
    ],'ventana_24h'=>$window,'usuarios'=>auth_can_whatsapp('bandeja_gestionar')?wa_inbox_users($pdo):[],
    'can_modify'=>auth_can_whatsapp_any(['bandeja_responder','bandeja_gestionar']),'can_reply'=>auth_can_whatsapp('bandeja_responder'),'can_manage'=>auth_can_whatsapp('bandeja_gestionar')]);
} catch (Throwable $e) { error_log('WhatsApp conversation: '.$e->getMessage()); whatsapp_json(false,'No se pudo abrir la conversación.',[],500); }
