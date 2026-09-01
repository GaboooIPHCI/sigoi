<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

/*
|--------------------------------------------------------------------------
| Permisos de lectura
|--------------------------------------------------------------------------
*/
auth_require_module_view('pacientes');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {

    $sql = "
        SELECT
            id,
            fecha_contacto,
            paciente,
            telefono,
            dni,
            canal,
            tipo_atencion,
            servicio,
            nombre_referido,
            detalle_consulta,
            status_cita,
            fecha_cita,
            doctor_cita,
            modulo
        FROM atenciones
        ORDER BY id DESC
    ";

    $stmt = $pdo->query($sql);

    $records = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'records' => $records
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'No se pudo cargar el listado de atenciones.'
    ], JSON_UNESCAPED_UNICODE);
}