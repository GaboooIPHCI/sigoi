<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_view('campanias');

try {
    global $pdo;

    $campaniaId = isset($_GET['campania_id']) ? (int) $_GET['campania_id'] : 0;
    $search = normalizeText($_GET['search'] ?? '');
    $confirmoCita = normalizeText($_GET['confirmo_cita'] ?? '');
    $asistio = normalizeText($_GET['asistio'] ?? '');

    if ($campaniaId <= 0) {
        jsonResponse(false, 'ID de campaña inválido', null, 422);
    }

    $sql = "
        SELECT
            id,
            campania_id,
            nombre_apellido,
            numero_telefono,
            dni,
            confirmo_cita,
            asistio,
            monto,
            que_se_realizo,
            created_at,
            updated_at
        FROM campania_registros
        WHERE campania_id = :campania_id
    ";

    $params = [
        ':campania_id' => $campaniaId
    ];

    if ($search !== '') {
        $sql .= " AND (nombre_apellido LIKE :search OR numero_telefono LIKE :search OR dni LIKE :search)";
        $params[':search'] = "%{$search}%";
    }

    if ($confirmoCita !== '' && in_array($confirmoCita, ['si', 'no', 'pendiente'], true)) {
        $sql .= " AND confirmo_cita = :confirmo_cita";
        $params[':confirmo_cita'] = $confirmoCita;
    }

    if ($asistio !== '' && in_array($asistio, ['asistio', 'no_asistio'], true)) {
        $sql .= " AND asistio = :asistio";
        $params[':asistio'] = $asistio;
    }

    $sql .= " ORDER BY id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonResponse(true, 'Registros cargados correctamente', $rows);
} catch (Throwable $e) {
    jsonResponse(false, 'Error al listar registros', null, 500);
}