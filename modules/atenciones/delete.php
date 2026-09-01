<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_modify('pacientes');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_json_response([
        'success' => false,
        'message' => 'Método no permitido.'
    ], 405);
}

/*
|--------------------------------------------------------------------------
| Protección CSRF
|--------------------------------------------------------------------------
| La eliminación necesita una sesión válida y el token generado por IPHCI.
|--------------------------------------------------------------------------
*/
auth_validate_csrf();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id <= 0) {
    auth_json_response([
        'success' => false,
        'message' => 'ID inválido.'
    ], 400);
}

try {
    $pdo->beginTransaction();

    $checkStmt = $pdo->prepare("
        SELECT id
        FROM atenciones
        WHERE id = :id
        LIMIT 1
    ");

    $checkStmt->execute([
        ':id' => $id
    ]);

    if (!$checkStmt->fetch()) {
        $pdo->rollBack();

        auth_json_response([
            'success' => false,
            'message' => 'El registro no existe.'
        ], 404);
    }

    $deleteConvenioStmt = $pdo->prepare("
        DELETE FROM convenios
        WHERE atencion_id = :id
    ");

    $deleteConvenioStmt->execute([
        ':id' => $id
    ]);

    $deleteAtencionStmt = $pdo->prepare("
        DELETE FROM atenciones
        WHERE id = :id
    ");

    $deleteAtencionStmt->execute([
        ':id' => $id
    ]);

    $pdo->commit();

    auth_json_response([
        'success' => true,
        'message' => 'Registro eliminado correctamente.'
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    auth_json_response([
        'success' => false,
        'message' => 'No se pudo eliminar el registro.'
    ], 500);
}