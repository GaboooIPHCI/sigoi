<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_view('convenios');

try {
    $search = trim($_GET['search'] ?? '');
    $derivadoA = trim($_GET['derivado_a'] ?? '');

    $allowedDerivados = ['Resocentro', 'Servimovil'];

    if ($derivadoA !== '' && !in_array($derivadoA, $allowedDerivados, true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Filtro derivado_a inválido'
        ]);
        exit;
    }

    $sql = "
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
        WHERE 1=1
    ";

    $params = [];

    if ($search !== '') {
        $sql .= " AND (a.paciente LIKE :search OR c.estudio LIKE :search)";
        $params[':search'] = "%{$search}%";
    }

    if ($derivadoA !== '') {
        $sql .= " AND c.derivado_a = :derivado_a";
        $params[':derivado_a'] = $derivadoA;
    }

    $sql .= " ORDER BY c.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'records' => $records
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al listar convenios'
    ]);
}