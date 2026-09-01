<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_modify('convenios');
auth_validate_csrf();

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($data['id']) ? (int) $data['id'] : 0;

    if ($id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'ID de convenio inválido'
        ]);
        exit;
    }

    $checkStmt = $pdo->prepare("SELECT id FROM convenios WHERE id = :id LIMIT 1");
    $checkStmt->execute([':id' => $id]);

    if (!$checkStmt->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'Convenio no encontrado'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM convenios WHERE id = :id");
    $stmt->execute([':id' => $id]);

    echo json_encode([
        'success' => true,
        'message' => 'Convenio eliminado correctamente'
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al eliminar convenio'
    ]);
}