<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../config/auth.php';
auth_require_module_modify('campanias');
auth_validate_csrf();


try {
    global $pdo;

    $data = requestData();
    $id = isset($data['id']) ? (int) $data['id'] : 0;

    if ($id <= 0) {
        jsonResponse(false, 'ID de campaña inválido', null, 422);
    }

    $checkStmt = $pdo->prepare("
        SELECT id
        FROM campanias
        WHERE id = :id
        LIMIT 1
    ");
    $checkStmt->execute([':id' => $id]);

    if (!$checkStmt->fetch()) {
        jsonResponse(false, 'La campaña no existe', null, 404);
    }

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM campania_registros
        WHERE campania_id = :campania_id
    ");
    $countStmt->execute([':campania_id' => $id]);
    $totalRegistros = (int) $countStmt->fetchColumn();

    if ($totalRegistros > 0) {
        jsonResponse(false, 'No se puede eliminar la campaña porque tiene registros asociados', null, 422);
    }

    $deleteExtrasStmt = $pdo->prepare("
        DELETE FROM campania_campos_extra
        WHERE campania_id = :campania_id
    ");
    $deleteExtrasStmt->execute([':campania_id' => $id]);

    $deleteStmt = $pdo->prepare("
        DELETE FROM campanias
        WHERE id = :id
    ");
    $deleteStmt->execute([':id' => $id]);

    jsonResponse(true, 'Campaña eliminada correctamente');
} catch (Throwable $e) {
    jsonResponse(false, 'Error al eliminar campaña: ' . $e->getMessage(), null, 500);
}