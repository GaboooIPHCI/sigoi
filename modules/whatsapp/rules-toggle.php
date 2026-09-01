<?php
require_once __DIR__ . '/_helpers.php';
auth_require_whatsapp_permission('automatizacion_modificar');
auth_validate_csrf();

try {
    global $pdo;
    $data = whatsapp_request();
    $id = (int) ($data['id'] ?? 0);
    $active = !empty($data['activa']) ? 1 : 0;

    if ($id <= 0) {
        whatsapp_json(false, 'Regla inválida.', [], 422);
    }

    $stmt = $pdo->prepare("UPDATE whatsapp_reglas
        SET activa = :activa, actualizado_por = :usuario
        WHERE id = :id");
    $stmt->execute([
        ':activa' => $active,
        ':usuario' => auth_user_id(),
        ':id' => $id,
    ]);

    whatsapp_json(true, $active ? 'Regla activada.' : 'Regla desactivada.');
} catch (Throwable $e) {
    error_log('WhatsApp rules toggle: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo cambiar el estado de la regla.', [], 500);
}
