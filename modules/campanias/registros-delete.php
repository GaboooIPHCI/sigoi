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
        jsonResponse(false, 'ID de registro inválido', null, 422);
    }

    $checkStmt = $pdo->prepare("
        SELECT id
        FROM campania_registros
        WHERE id = :id
        LIMIT 1
    ");
    $checkStmt->execute([':id' => $id]);

    if (!$checkStmt->fetch()) {
        jsonResponse(false, 'El registro no existe', null, 404);
    }

    $pdo->beginTransaction();

    $deleteExtrasStmt = $pdo->prepare("
        DELETE FROM campania_registro_campos_extra
        WHERE registro_id = :registro_id
    ");
    $deleteExtrasStmt->execute([':registro_id' => $id]);

    $deleteStmt = $pdo->prepare("
        DELETE FROM campania_registros
        WHERE id = :id
    ");
    $deleteStmt->execute([':id' => $id]);

    $pdo->commit();

    jsonResponse(true, 'Registro eliminado correctamente');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    jsonResponse(false, 'Error al eliminar registro: ' . $e->getMessage(), null, 500);
}