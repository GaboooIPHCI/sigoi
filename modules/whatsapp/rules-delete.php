<?php
require_once __DIR__ . '/_helpers.php';
auth_require_whatsapp_permission('automatizacion_modificar');
auth_validate_csrf();

try {
    global $pdo;
    $data = whatsapp_request();
    $id = (int) ($data['id'] ?? 0);

    if ($id <= 0) {
        whatsapp_json(false, 'Regla inválida.', [], 422);
    }

    $stmt = $pdo->prepare("DELETE FROM whatsapp_reglas WHERE id = :id");
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        whatsapp_json(false, 'La regla ya no existe.', [], 404);
    }

    whatsapp_json(true, 'Regla eliminada.');
} catch (Throwable $e) {
    error_log('WhatsApp rules delete: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo eliminar la regla.', [], 500);
}
