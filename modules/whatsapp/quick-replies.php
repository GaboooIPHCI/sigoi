<?php
require_once __DIR__ . '/_template_helpers.php';

try {
    global $pdo;
    wa_templates_ensure_schema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        auth_require_whatsapp_any(['plantillas_ver', 'bandeja_responder']);

        $canManageLibraryView = auth_can_whatsapp('plantillas_ver');
        $activeOnly = (int)($_GET['active'] ?? 0) === 1 || !$canManageLibraryView;
        $sql = "SELECT id, titulo, atajo, categoria, contenido, activa, creado_en, actualizado_en
                FROM whatsapp_respuestas_rapidas";
        if ($activeOnly) $sql .= " WHERE activa = 1";
        $sql .= " ORDER BY categoria ASC, titulo ASC, id ASC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['activa'] = (int)$row['activa'];
        }
        unset($row);
        whatsapp_json(true, '', ['respuestas' => $rows]);
    }

    auth_require_whatsapp_permission('plantillas_gestionar');
    auth_validate_csrf();
    $data = whatsapp_request();
    $action = whatsapp_clean_text($data['action'] ?? 'save', 30);

    if ($action === 'save') {
        $id = (int)($data['id'] ?? 0);
        $title = whatsapp_clean_text($data['titulo'] ?? '', 120);
        $shortcut = wa_templates_quick_slug(whatsapp_clean_text($data['atajo'] ?? '', 80));
        $category = whatsapp_clean_text($data['categoria'] ?? 'General', 80);
        $content = whatsapp_clean_text($data['contenido'] ?? '', 4000);
        $active = !isset($data['activa']) || (bool)$data['activa'] ? 1 : 0;

        if ($title === '' || $content === '') {
            whatsapp_json(false, 'Completa el nombre y el mensaje de la respuesta rápida.', [], 422);
        }
        if ($shortcut === '') $shortcut = wa_templates_quick_slug($title);
        if ($shortcut === '') {
            whatsapp_json(false, 'No se pudo generar un atajo válido.', [], 422);
        }
        if ($category === '') $category = 'General';

        $duplicate = $pdo->prepare("SELECT id FROM whatsapp_respuestas_rapidas WHERE atajo = :atajo AND id <> :id LIMIT 1");
        $duplicate->execute([':atajo' => $shortcut, ':id' => $id]);
        if ($duplicate->fetchColumn()) {
            whatsapp_json(false, 'Ya existe una respuesta rápida con el atajo /' . $shortcut . '.', [], 409);
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE whatsapp_respuestas_rapidas
                SET titulo = :titulo, atajo = :atajo, categoria = :categoria, contenido = :contenido,
                    activa = :activa, actualizado_por = :usuario
                WHERE id = :id");
            $stmt->execute([
                ':titulo' => $title, ':atajo' => $shortcut, ':categoria' => $category,
                ':contenido' => $content, ':activa' => $active, ':usuario' => auth_user_id(), ':id' => $id,
            ]);
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare("SELECT id FROM whatsapp_respuestas_rapidas WHERE id = :id");
                $check->execute([':id' => $id]);
                if (!$check->fetchColumn()) whatsapp_json(false, 'La respuesta rápida ya no existe.', [], 404);
            }
            $message = 'Respuesta rápida actualizada.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO whatsapp_respuestas_rapidas
                (titulo, atajo, categoria, contenido, activa, creado_por, actualizado_por)
                VALUES (:titulo, :atajo, :categoria, :contenido, :activa, :usuario, :usuario2)");
            $stmt->execute([
                ':titulo' => $title, ':atajo' => $shortcut, ':categoria' => $category,
                ':contenido' => $content, ':activa' => $active,
                ':usuario' => auth_user_id(), ':usuario2' => auth_user_id(),
            ]);
            $id = (int)$pdo->lastInsertId();
            $message = 'Respuesta rápida creada.';
        }

        auth_audit($pdo, 'whatsapp_respuesta_rapida_guardada', auth_user_id(), auth_username(), json_encode(['id'=>$id,'atajo'=>$shortcut], JSON_UNESCAPED_UNICODE) ?: null);
        whatsapp_json(true, $message, ['id' => $id]);
    }

    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) whatsapp_json(false, 'Respuesta rápida inválida.', [], 422);
        $stmt = $pdo->prepare("DELETE FROM whatsapp_respuestas_rapidas WHERE id = :id");
        $stmt->execute([':id' => $id]);
        auth_audit($pdo, 'whatsapp_respuesta_rapida_eliminada', auth_user_id(), auth_username(), json_encode(['id'=>$id]) ?: null);
        whatsapp_json(true, 'Respuesta rápida eliminada.');
    }

    whatsapp_json(false, 'Acción no reconocida.', [], 422);
} catch (PDOException $e) {
    error_log('WhatsApp quick replies DB: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo guardar la respuesta rápida.', [], 500);
} catch (Throwable $e) {
    error_log('WhatsApp quick replies: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo completar la operación.', [], 500);
}
