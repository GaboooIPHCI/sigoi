<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_view('campanias');

try {
    global $pdo;

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($id <= 0) {
        jsonResponse(false, 'ID de campaña inválido', null, 422);
    }

    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.nombre,
            c.descripcion,
            c.fecha_inicio,
            c.estado,
            COUNT(cr.id) AS total_registros
        FROM campanias c
        LEFT JOIN campania_registros cr ON cr.campania_id = c.id
        WHERE c.id = :id
        GROUP BY c.id, c.nombre, c.descripcion, c.fecha_inicio, c.estado
        LIMIT 1
    ");

    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonResponse(false, 'La campaña no existe', null, 404);
    }

    jsonResponse(true, 'Campaña cargada correctamente', $row);
} catch (Throwable $e) {
    jsonResponse(false, 'Error al obtener campaña: ' . $e->getMessage(), null, 500);
}
