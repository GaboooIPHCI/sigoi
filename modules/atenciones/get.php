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

$id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (
    $id === false ||
    $id === null ||
    $id <= 0
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'ID inválido.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {

    $sql = "
        SELECT
            a.id,
            a.fecha_contacto,
            a.paciente,
            a.telefono,
            a.dni,
            a.canal,
            a.tipo_atencion,
            a.servicio,
            a.nombre_referido,
            a.detalle_consulta,
            a.status_cita,
            a.fecha_cita,
            a.doctor_cita,
            a.modulo,
            a.created_at,
            a.updated_at,

            c.fecha_realizar AS convenio_fecha_realizar,
            c.derivado_a AS convenio_derivado_a,
            c.estudio AS convenio_estudio,
            c.medico_derivado AS convenio_medico_derivado

        FROM atenciones a

        LEFT JOIN convenios c
            ON c.atencion_id = a.id

        WHERE a.id = :id

        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':id' => $id
    ]);

    $record = $stmt->fetch();

    if (!$record) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Registro no encontrado.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    echo json_encode([
        'success' => true,
        'record' => $record
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'No se pudo obtener la información de la atención.'
    ], JSON_UNESCAPED_UNICODE);
}