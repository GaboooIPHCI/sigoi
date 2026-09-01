<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_view('convenios');

try {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'ID de convenio inválido'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.atencion_id,
            a.paciente,
            c.fecha_realizar,
            c.derivado_a,
            c.estudio,
            c.medico_derivado
        FROM convenios c
        INNER JOIN atenciones a ON a.id = c.atencion_id
        WHERE c.id = :id
        LIMIT 1
    ");

    $stmt->execute([':id' => $id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        echo json_encode([
            'success' => false,
            'message' => 'Convenio no encontrado'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => $record
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener convenio'
    ]);
}