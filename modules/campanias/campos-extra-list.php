<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_view('campanias');



try {
    global $pdo;

    $campaniaId = isset($_GET['campania_id']) ? (int) $_GET['campania_id'] : 0;

    if ($campaniaId <= 0) {
        jsonResponse(false, 'ID de campaña inválido', null, 422);
    }

    $stmt = $pdo->prepare("
        SELECT 
            id,
            campania_id,
            nombre_interno,
            etiqueta,
            tipo,
            requerido,
            opciones_json,
            orden,
            activo
        FROM campania_campos_extra
        WHERE campania_id = :campania_id
        ORDER BY orden ASC, id ASC
    ");

    $stmt->execute([
        ':campania_id' => $campaniaId
    ]);

    $rows = $stmt->fetchAll();

    jsonResponse(true, 'Campos extra cargados correctamente', $rows);
} catch (Throwable $e) {
    jsonResponse(false, 'Error al listar campos extra: ' . $e->getMessage(), null, 500);
}