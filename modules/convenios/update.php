<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_modify('convenios');
auth_validate_csrf();

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $atencionId = isset($data['atencion_id']) ? (int) $data['atencion_id'] : 0;
    $fechaRealizar = trim($data['fecha_realizar'] ?? '');
    $derivadoA = trim($data['derivado_a'] ?? '');
    $estudio = trim($data['estudio'] ?? '');
    $medicoDerivado = trim($data['medico_derivado'] ?? '');

    if ($id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'ID de convenio inválido'
        ]);
        exit;
    }

    if ($atencionId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Atención inválida'
        ]);
        exit;
    }

    if ($fechaRealizar === '') {
        echo json_encode([
            'success' => false,
            'message' => 'La fecha a realizar es obligatoria'
        ]);
        exit;
    }

    if (!in_array($derivadoA, ['Resocentro', 'Servimovil'], true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Derivado a no es válido'
        ]);
        exit;
    }

    if ($estudio === '') {
        echo json_encode([
            'success' => false,
            'message' => 'El estudio es obligatorio'
        ]);
        exit;
    }

    if ($medicoDerivado === '') {
        echo json_encode([
            'success' => false,
            'message' => 'El médico derivado es obligatorio'
        ]);
        exit;
    }

    $checkStmt = $pdo->prepare("
        SELECT id, atencion_id
        FROM convenios
        WHERE id = :id
        LIMIT 1
    ");
    $checkStmt->execute([':id' => $id]);
    $convenioActual = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$convenioActual) {
        echo json_encode([
            'success' => false,
            'message' => 'Convenio no encontrado'
        ]);
        exit;
    }

    if ((int)$convenioActual['atencion_id'] !== $atencionId) {
        echo json_encode([
            'success' => false,
            'message' => 'No se puede cambiar el convenio a otra atención'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE convenios
        SET
            fecha_realizar = :fecha_realizar,
            derivado_a = :derivado_a,
            estudio = :estudio,
            medico_derivado = :medico_derivado
        WHERE id = :id
    ");

    $stmt->execute([
        ':fecha_realizar' => $fechaRealizar,
        ':derivado_a' => $derivadoA,
        ':estudio' => $estudio,
        ':medico_derivado' => $medicoDerivado,
        ':id' => $id
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Convenio actualizado correctamente'
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al actualizar convenio'
    ]);
}